import { ImageResponse } from 'next/og';
import { resolveRequestHost, fetchOrganisationBrandingServer } from './organisationBrandingServer';

export const brandedOgImageSize = { width: 1200, height: 630 };
export const brandedOgImageContentType = 'image/png';

/**
 * Organisation URL Branding, Phase 4 — shared per-segment OpenGraph image
 * for the 3 branded token routes. Resolves branding from the request's
 * own Host header only (never a query parameter — see brandedMetadata.ts's
 * docblock for why) and embeds the organisation's own `logo_url`, which is
 * always a trusted, backend-issued URL under our own storage domain, never
 * anything client-supplied. Falls back to the exact same default SureSign
 * image the root layout already uses whenever branding isn't cleanly
 * `resolved`.
 */
export async function renderBrandedOgImage() {
  const host = await resolveRequestHost();
  const result = host ? await fetchOrganisationBrandingServer(host) : { status: 'unavailable' as const };

  if (result.status !== 'resolved') {
    return renderDefaultOgImage();
  }

  const { organisation_name, logo_url, accent_color } = result.data;

  return new ImageResponse(
    (
      <div
        style={{
          width: '100%',
          height: '100%',
          display: 'flex',
          flexDirection: 'column',
          justifyContent: 'space-between',
          background: '#f4f4f1',
          color: '#0a0a0a',
          padding: '64px 72px',
          fontFamily: 'sans-serif',
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: 20 }}>
          {logo_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={logo_url} alt="" width={64} height={64} style={{ objectFit: 'contain' }} />
          ) : null}
          <div style={{ display: 'flex', fontSize: 40, fontWeight: 600, letterSpacing: '-1px' }}>{organisation_name}</div>
        </div>
        <div style={{ display: 'flex', flexDirection: 'column', maxWidth: 980 }}>
          <div style={{ display: 'flex', fontSize: 48, lineHeight: 1.1, fontWeight: 600, letterSpacing: '-2px' }}>
            Your workspace with {organisation_name}
          </div>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 14, fontSize: 20 }}>
          <div
            style={{ display: 'flex', width: 10, height: 10, borderRadius: 10, background: accent_color || '#0a0a0a' }}
          />
          Powered by SureSign
        </div>
      </div>
    ),
    brandedOgImageSize,
  );
}

function renderDefaultOgImage() {
  return new ImageResponse(
    (
      <div
        style={{
          width: '100%',
          height: '100%',
          display: 'flex',
          flexDirection: 'column',
          justifyContent: 'space-between',
          background: '#f4f4f1',
          color: '#0a0a0a',
          padding: '64px 72px',
          fontFamily: 'sans-serif',
          backgroundImage:
            'linear-gradient(#deded9 1px, transparent 1px), linear-gradient(90deg, #deded9 1px, transparent 1px)',
          backgroundSize: '52px 52px',
        }}
      >
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <div style={{ display: 'flex', fontSize: 30, fontWeight: 600, letterSpacing: '-1px' }}>SureSign</div>
          <div style={{ display: 'flex', fontSize: 18, color: '#5d5d58' }}>Construction contract administration</div>
        </div>
        <div style={{ display: 'flex', flexDirection: 'column', maxWidth: 980 }}>
          <div style={{ display: 'flex', fontSize: 60, lineHeight: 1.05, fontWeight: 600, letterSpacing: '-3px' }}>
            Manage your appointment with SureSign.
          </div>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 14, fontSize: 18 }}>
          <div style={{ display: 'flex', width: 10, height: 10, borderRadius: 10, background: '#0a0a0a' }} />
          suresigncontracts.app
        </div>
      </div>
    ),
    brandedOgImageSize,
  );
}
