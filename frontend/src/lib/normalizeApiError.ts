// The one canonical frontend error-normalization utility — Error Messaging &
// Recovery UX, Batch 1 (shared foundation). Consolidates what were
// previously 8 near-identical, independently-authored `getErrorMessage()`
// implementations (see internal-docs/error-messaging-recovery-ux-audit.md
// §2/§4) into a single structured normalizer, with `getErrorMessage()` kept
// as a thin backward-compatible accessor on top so existing callers do not
// need to migrate in this batch.
//
// Duck-types the error shape (checks for a `response` property) rather than
// using `axios.isAxiosError()` — this repo's own authStore.login() throws a
// plain object literal (`{ response: { data, status } }`) to represent a
// dev-server quirk (see authStore.ts), which is not a real AxiosError
// instance but must normalize identically.

export type NormalizedErrorType =
  | 'validation'
  | 'authentication'
  | 'permission'
  | 'not_found'
  | 'conflict'
  | 'rate_limit'
  | 'network'
  | 'server'
  | 'unknown';

export interface NormalizedApiError {
  type: NormalizedErrorType;
  title: string;
  message: string;
  /** Laravel's 422 `errors` object, normalized to `{ field: string[] }`. */
  fieldErrors?: Record<string, string[]>;
  /** The backend's structured `code` field, when present (see the existing
   *  Billing/account-status convention) — prefer this over string-matching
   *  `message` for any deterministic frontend behaviour. */
  code?: string;
  retryable: boolean;
  status?: number;
}

interface ErrorResponseShape {
  status?: number;
  data?: {
    message?: string;
    errors?: Record<string, string[] | string>;
    code?: string;
  };
}

function hasResponse(error: unknown): error is { response?: ErrorResponseShape } {
  return typeof error === 'object' && error !== null && 'response' in error;
}

function normalizeFieldErrors(
  errors: Record<string, string[] | string> | undefined,
): Record<string, string[]> | undefined {
  if (!errors) return undefined;
  const normalized: Record<string, string[]> = {};
  for (const [field, value] of Object.entries(errors)) {
    normalized[field] = Array.isArray(value) ? value : [value];
  }
  return Object.keys(normalized).length > 0 ? normalized : undefined;
}

const TITLES: Record<NormalizedErrorType, string> = {
  validation: 'Check the highlighted information',
  authentication: 'Sign in required',
  permission: "You don't have permission",
  not_found: "We couldn't find this",
  conflict: "This isn't available right now",
  rate_limit: 'Too many attempts',
  network: "We couldn't reach SureSign",
  server: 'Something went wrong on our side',
  unknown: 'Something went wrong',
};

/**
 * Normalizes an Axios (or Axios-shaped) error into a small structured
 * representation a caller can use to decide surface (inline/toast/page) and
 * copy, without re-deriving status-code logic itself.
 *
 * `fallback` is used as the `message` only where trusting the backend's own
 * message would be unsafe or absent — most importantly, a 5xx response's
 * `message` is never trusted verbatim (Laravel's own production default is
 * the bare word "Server Error", and a debug-mode response can carry far
 * more than that) — see the audit's §5 requirement not to blindly trust
 * every backend message.
 */
export function normalizeApiError(error: unknown, fallback?: string): NormalizedApiError {
  const genericFallback = fallback ?? 'Something went wrong. Please try again.';

  if (!hasResponse(error) || !error.response) {
    // No response reached the client at all — treated uniformly as a
    // network-layer failure. Deliberately does NOT fall back to a bare
    // `Error`'s own `.message` here (unlike one pre-existing local helper —
    // see delay-eot/page.tsx's own getErrorMessage, kept un-consolidated for
    // exactly this reason): a stray non-Axios exception reaching this far is
    // more often an unexpected runtime bug than an intentionally-authored
    // message, and surfacing an arbitrary JS error message verbatim would
    // reintroduce the technical-leak risk this utility exists to prevent.
    return {
      type: 'network',
      title: TITLES.network,
      message: "We couldn't reach SureSign. Check your connection and try again.",
      retryable: true,
    };
  }

  const { status, data } = error.response;
  const code = data?.code;
  const fieldErrors = normalizeFieldErrors(data?.errors);

  switch (status) {
    case 422:
      return {
        type: 'validation',
        title: TITLES.validation,
        message: data?.message || 'Some information is missing or invalid.',
        fieldErrors,
        code,
        retryable: false,
        status,
      };
    case 401:
      return {
        type: 'authentication',
        title: TITLES.authentication,
        message: data?.message || 'You need to sign in to continue.',
        code,
        retryable: false,
        status,
      };
    case 403:
      return {
        type: 'permission',
        title: TITLES.permission,
        // Backend 403 messages in this codebase are already written to be
        // customer-safe (authorize()/abort(403, ...) convention — see the
        // audit's §3) — safe to trust directly, unlike 5xx below.
        message: data?.message || "You don't have permission to perform this action.",
        code,
        retryable: false,
        status,
      };
    case 404:
      return {
        type: 'not_found',
        title: TITLES.not_found,
        message: data?.message || "We couldn't find this record.",
        code,
        retryable: false,
        status,
      };
    case 409:
      return {
        type: 'conflict',
        title: TITLES.conflict,
        message: data?.message || "This action isn't available in the current status.",
        code,
        retryable: false,
        status,
      };
    case 429:
      return {
        type: 'rate_limit',
        title: TITLES.rate_limit,
        message: data?.message || 'Too many attempts. Wait a moment and try again.',
        code,
        retryable: true,
        status,
      };
    default:
      if (status !== undefined && status >= 500) {
        // Deliberately never uses `data?.message` here — see this
        // function's own docblock.
        return {
          type: 'server',
          title: TITLES.server,
          message: 'Something went wrong on our side. Please try again.',
          code,
          retryable: true,
          status,
        };
      }
      return {
        type: 'unknown',
        title: TITLES.unknown,
        message: data?.message || genericFallback,
        code,
        retryable: false,
        status,
      };
  }
}

/**
 * Backward-compatible accessor — returns just the display string, matching
 * the signature every existing call site already expects
 * (`getErrorMessage(error, fallback): string`). New call sites that need
 * field errors, retryability, or the structured type should call
 * `normalizeApiError()` directly instead.
 */
export function getErrorMessage(error: unknown, fallback: string): string {
  return normalizeApiError(error, fallback).message;
}

/** Returns the first server-reported message for a given field, if any. */
export function getFieldError(normalized: NormalizedApiError, field: string): string | undefined {
  return normalized.fieldErrors?.[field]?.[0];
}
