// The one toast entry point for the whole app — a drop-in for the
// react-hot-toast API this codebase used everywhere (`toast(...)`,
// `toast.success(...)`, `toast.error(...)`, occasionally with a second
// `{ icon }` options argument), now backed by goey-toast's morphing/blob
// toast instead. Every call site imports from here rather than
// 'goey-toast' directly, so there's exactly one place the underlying
// library — and its branding — is defined.
//
// The morphing silhouette/layout belongs entirely to goey-toast; SureSign
// only supplies fillColor/borderColor per type (its own supported options)
// plus colour-only CSS in globals.css. Nothing here or there touches size,
// padding, or shape — every earlier attempt to adjust those fought the
// library's own internal geometry assumptions and kept reintroducing new
// visual bugs. Callers can still override these defaults.
import { gooeyToast } from 'goey-toast';
import type { GooeyToastAction, GooeyToastOptions, GooeyPromiseData } from 'goey-toast';

export type { GooeyToastAction, GooeyToastOptions, GooeyPromiseData };

const BRAND = {
  default: { fillColor: '#18211d', borderColor: 'rgba(255,255,255,0.12)', borderWidth: 1 },
  success: { fillColor: '#18211d', borderColor: 'rgba(158,229,181,0.72)', borderWidth: 1 },
  error: { fillColor: '#18211d', borderColor: 'rgba(248,113,113,0.72)', borderWidth: 1 },
  warning: { fillColor: '#18211d', borderColor: 'rgba(251,191,36,0.72)', borderWidth: 1 },
  info: { fillColor: '#18211d', borderColor: 'rgba(147,197,253,0.72)', borderWidth: 1 },
} as const;

function withBrand(
  defaults: { fillColor: string; borderColor: string; borderWidth: number },
  options?: GooeyToastOptions,
): GooeyToastOptions {
  return { ...defaults, ...options };
}

const toast = Object.assign(
  (title: string, options?: GooeyToastOptions) => gooeyToast(title, withBrand(BRAND.default, options)),
  {
    success: (title: string, options?: GooeyToastOptions) => gooeyToast.success(title, withBrand(BRAND.success, options)),
    error: (title: string, options?: GooeyToastOptions) => gooeyToast.error(title, withBrand(BRAND.error, options)),
    warning: (title: string, options?: GooeyToastOptions) => gooeyToast.warning(title, withBrand(BRAND.warning, options)),
    info: (title: string, options?: GooeyToastOptions) => gooeyToast.info(title, withBrand(BRAND.info, options)),
    // Promise toasts move between states, so the stable forest shell is the
    // useful constant while the library changes the icon and status treatment.
    promise: <T,>(promise: Promise<T>, data: GooeyPromiseData<T>) =>
      gooeyToast.promise(promise, { ...BRAND.default, ...data }),
    update: gooeyToast.update,
    dismiss: gooeyToast.dismiss,
  }
);

export default toast;
export { gooeyToast };
