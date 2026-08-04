import { ImageResponse } from 'next/og';
import { resolveRequestHost, fetchOrganisationBrandingServer } from './organisationBrandingServer';

export const brandedIconSize = { width: 32, height: 32 };
export const brandedIconContentType = 'image/png';

/**
 * Organisation URL Branding, Phase 4 — shared per-segment favicon for the
 * 3 branded token routes. Uses the organisation's own trusted `logo_url`
 * when resolved; otherwise renders a plain SureSign monogram matching the
 * root layout's own default favicon in spirit (this route only exists on
 * the 3 branded pages — everywhere else keeps using the static
 * favicon.svg via the root layout, untouched).
 */
export async function renderBrandedIcon() {
  const host = await resolveRequestHost();
  const result = host ? await fetchOrganisationBrandingServer(host) : { status: 'unavailable' as const };

  if (result.status === 'resolved' && result.data.logo_url) {
    return new ImageResponse(
      (
        <div
          style={{
            width: '100%',
            height: '100%',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            background: '#ffffff',
          }}
        >
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={result.data.logo_url} alt="" width={28} height={28} style={{ objectFit: 'contain' }} />
        </div>
      ),
      brandedIconSize,
    );
  }

  return new ImageResponse(
    (
      <div
        style={{
          width: '100%',
          height: '100%',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          background: '#0a0a0a',
          color: '#ffffff',
          fontSize: 20,
          fontWeight: 700,
          fontFamily: 'sans-serif',
        }}
      >
        S
      </div>
    ),
    brandedIconSize,
  );
}
