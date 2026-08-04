import { useCallback, useEffect, useRef } from 'react';
import type { UIEvent } from 'react';

const HIDE_DELAY_MS = 700;

/** Shows a sidebar scrollbar only while its container is actively scrolling. */
export function useAutoHideScrollbar() {
  const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const handleScroll = useCallback((event: UIEvent<HTMLElement>) => {
    const element = event.currentTarget;
    element.classList.add('is-scrolling');

    if (timeoutRef.current) clearTimeout(timeoutRef.current);
    timeoutRef.current = setTimeout(() => {
      element.classList.remove('is-scrolling');
      timeoutRef.current = null;
    }, HIDE_DELAY_MS);
  }, []);

  useEffect(() => () => {
    if (timeoutRef.current) clearTimeout(timeoutRef.current);
  }, []);

  return handleScroll;
}
