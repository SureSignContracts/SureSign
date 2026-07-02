'use client';

import { useState, useEffect } from 'react';

/**
 * Returns true when the viewport is below the `lg` breakpoint (1024px) — i.e.
 * tablet-portrait and mobile, where the persistent sidebar becomes a drawer.
 *
 * Defaults to `false` so server render and first client render match the
 * desktop layout (avoids hydration mismatch); it flips to the real value
 * after mount. The drawer is hidden off-screen until opened, so this initial
 * `false` is never visible to mobile users.
 */
export function useIsMobile(query = '(max-width: 1023px)'): boolean {
  const [isMobile, setIsMobile] = useState(false);

  useEffect(() => {
    const mql = window.matchMedia(query);
    const update = () => setIsMobile(mql.matches);
    update();
    mql.addEventListener('change', update);
    return () => mql.removeEventListener('change', update);
  }, [query]);

  return isMobile;
}
