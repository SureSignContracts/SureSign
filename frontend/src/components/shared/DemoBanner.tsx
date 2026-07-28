'use client';

import { useEffect, useState } from 'react';

/**
 * Small, professional strip identifying this as the SureSign demo
 * environment — only ever renders when NEXT_PUBLIC_DEMO_MODE is baked into
 * the build (the demo frontend image only; production never sets this
 * build arg, so this component is always inert there).
 *
 * Two ways to hide it for a screenshot session, per the approved demo
 * deployment plan:
 * - `?hideDemoBanner=1` in the URL hides it for that page load only,
 *   without persisting anything — quickest for a single capture.
 * - The close button persists the choice in localStorage, so a whole
 *   capture session doesn't need the query param on every page.
 */
export default function DemoBanner() {
  const [dismissed, setDismissed] = useState(true);

  useEffect(() => {
    const hiddenByQuery = new URLSearchParams(window.location.search).get('hideDemoBanner') === '1';
    const hiddenByStorage = window.localStorage.getItem('suresign-demo-banner-dismissed') === '1';
    setDismissed(hiddenByQuery || hiddenByStorage);
  }, []);

  if (process.env.NEXT_PUBLIC_DEMO_MODE !== 'true' || dismissed) {
    return null;
  }

  return (
    <div
      role="status"
      style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        gap: '0.75rem',
        padding: '0.4rem 1rem',
        fontSize: '0.8rem',
        lineHeight: 1.4,
        background: '#1E3A5F',
        color: '#F4F6F8',
        borderBottom: '1px solid #D98E29',
      }}
    >
      <span>
        <strong>SureSign Demo Environment</strong>
        {' · '}
        Demo Version {process.env.NEXT_PUBLIC_DEMO_VERSION || '1.0.0'}
        {' · '}
        Fictional demonstration data
      </span>
      <button
        type="button"
        aria-label="Hide demo banner"
        onClick={() => {
          window.localStorage.setItem('suresign-demo-banner-dismissed', '1');
          setDismissed(true);
        }}
        style={{
          background: 'transparent',
          border: 'none',
          color: 'inherit',
          cursor: 'pointer',
          fontSize: '0.9rem',
          lineHeight: 1,
          padding: '0 0.25rem',
        }}
      >
        ×
      </button>
    </div>
  );
}
