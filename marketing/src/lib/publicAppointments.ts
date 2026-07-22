// API layer for the Phase 4 public appointment actions (cancel/reschedule
// confirmation pages reached from signed email links). Kept separate from
// any authenticated dashboard appointment API.
//
// Every request here is treated as opaque with respect to signing: this
// module never computes or recomputes a Laravel signature. It only ever
// forwards the `expires` and `signature` query parameters exactly as the
// browser received them (see `signedQueryFrom`) or appends the one
// explicitly-permitted extra parameter (`date`, for the reschedule slots
// endpoint, which is signed via `signed:date` on the backend precisely so
// this is safe — see AppointmentPublicLinkService::rescheduleSlotsApiUrl).

const API_BASE = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api';

export type AppointmentAction = 'cancel' | 'reschedule';

export interface AppointmentTypeSummary {
  name: string | null;
  slug: string | null;
  duration_minutes: number | null;
}

export interface AppointmentPublicView {
  reference: string;
  status: string;
  starts_at: string;
  ends_at: string;
  booking_timezone: string;
  appointment_type: AppointmentTypeSummary;
  can_cancel: boolean;
  can_reschedule: boolean;
  reschedule_slots_url: string | null;
}

export interface Slot {
  /** The slot's own calendar date, labelled in whichever timezone was requested — may differ from the requested `date` near a timezone's midnight boundary. */
  date: string;
  time: string;
}

export interface SlotsResponse {
  scheduling_mode: 'fixed' | 'manual';
  timezone?: string;
  slots: Slot[];
}

export type ApiResult<T> =
  | { ok: true; data: T }
  | { ok: false; kind: 'not_found' | 'invalid' | 'expired' | 'validation' | 'conflict' | 'network'; message: string };

/**
 * Extracts ONLY `expires` and `signature` from the URL the browser actually
 * has — never re-derives or edits them. Any other query param (e.g. the
 * marketing page's own `?action=` routing flag) is deliberately dropped,
 * since it was never part of what the backend signed.
 */
export function signedQueryFrom(searchParams: URLSearchParams): URLSearchParams | null {
  const expires = searchParams.get('expires');
  const signature = searchParams.get('signature');
  if (!expires || !signature) return null;

  const out = new URLSearchParams();
  out.set('expires', expires);
  out.set('signature', signature);
  return out;
}

/** Best-effort read of the (unencrypted, visible) `expires` param — used only to choose better copy between "expired" and "invalid", never to validate the signature itself. */
export function isPastExpiry(searchParams: URLSearchParams): boolean {
  const raw = searchParams.get('expires');
  if (!raw) return false;
  const expiresAt = Number(raw);
  if (!Number.isFinite(expiresAt)) return false;
  return expiresAt * 1000 < Date.now();
}

async function parseError(res: Response, searchParams: URLSearchParams): Promise<ApiResult<never>> {
  let message = 'Something went wrong — please try again.';
  try {
    const body = await res.json();
    if (typeof body?.message === 'string') message = body.message;
  } catch {
    // no JSON body — keep the default message
  }

  if (res.status === 404) {
    return { ok: false, kind: 'not_found', message: 'This link is no longer valid.' };
  }
  if (res.status === 403) {
    return isPastExpiry(searchParams)
      ? { ok: false, kind: 'expired', message: 'This link has expired.' }
      : { ok: false, kind: 'invalid', message: 'This appointment link is no longer active. Please use the latest email we sent you.' };
  }
  if (res.status === 409) {
    return { ok: false, kind: 'conflict', message };
  }
  if (res.status === 422) {
    return { ok: false, kind: 'validation', message };
  }
  return { ok: false, kind: 'network', message };
}

async function request<T>(url: string, searchParams: URLSearchParams, init?: RequestInit): Promise<ApiResult<T>> {
  try {
    const res = await fetch(url, {
      ...init,
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', ...(init?.headers || {}) },
    });
    if (!res.ok) return parseError(res, searchParams);
    const data = (await res.json()) as T;
    return { ok: true, data };
  } catch {
    return { ok: false, kind: 'network', message: "We couldn't reach SureSign — check your connection and try again." };
  }
}

export async function fetchAppointmentView(
  token: string,
  action: AppointmentAction,
  searchParams: URLSearchParams,
): Promise<ApiResult<AppointmentPublicView>> {
  const signed = signedQueryFrom(searchParams);
  if (!signed) {
    return { ok: false, kind: 'invalid', message: 'This appointment link is no longer active. Please use the latest email we sent you.' };
  }
  return request<AppointmentPublicView>(`${API_BASE}/public/appointments/${token}/${action}?${signed.toString()}`, signed);
}

export async function submitCancellation(
  token: string,
  searchParams: URLSearchParams,
  reason: string,
): Promise<ApiResult<{ status: string; message?: string }>> {
  const signed = signedQueryFrom(searchParams);
  if (!signed) {
    return { ok: false, kind: 'invalid', message: 'This appointment link is no longer active. Please use the latest email we sent you.' };
  }
  return request(`${API_BASE}/public/appointments/${token}/cancel?${signed.toString()}`, signed, {
    method: 'POST',
    body: JSON.stringify({ reason: reason || undefined }),
  });
}

export async function fetchRescheduleSlots(rescheduleSlotsUrl: string, date: string, timezone: string, signal?: AbortSignal): Promise<ApiResult<SlotsResponse>> {
  const url = new URL(rescheduleSlotsUrl);
  url.searchParams.set('date', date);
  url.searchParams.set('timezone', timezone);
  const dummySigned = new URLSearchParams();
  try {
    const res = await fetch(url.toString(), { signal, headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' } });
    if (!res.ok) return parseError(res, dummySigned);
    const data = (await res.json()) as SlotsResponse;
    return { ok: true, data };
  } catch (err) {
    if (err instanceof DOMException && err.name === 'AbortError') {
      return { ok: false, kind: 'network', message: 'Cancelled.' };
    }
    return { ok: false, kind: 'network', message: "We couldn't load available times — please try again." };
  }
}

export async function submitReschedule(
  token: string,
  searchParams: URLSearchParams,
  payload: { date: string; start_time: string; timezone: string },
): Promise<ApiResult<AppointmentPublicView>> {
  const signed = signedQueryFrom(searchParams);
  if (!signed) {
    return { ok: false, kind: 'invalid', message: 'This appointment link is no longer active. Please use the latest email we sent you.' };
  }
  return request<AppointmentPublicView>(`${API_BASE}/public/appointments/${token}/reschedule?${signed.toString()}`, signed, {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}
