import { ImageResponse } from 'next/og';

export const alt = 'SureSign construction contract administration';
export const size = { width: 1200, height: 630 };
export const contentType = 'image/png';

export default function OpenGraphImage() {
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
          <div style={{ display: 'flex', fontSize: 72, lineHeight: 1.02, fontWeight: 600, letterSpacing: '-4px' }}>
            Turn the contract into a controlled commercial workflow.
          </div>
          <div style={{ display: 'flex', marginTop: 30, fontSize: 24, lineHeight: 1.4, color: '#52524e', maxWidth: 850 }}>
            Human-reviewed contract intelligence, connected commercial workflows and one project record.
          </div>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 14, fontSize: 18 }}>
          <div style={{ display: 'flex', width: 10, height: 10, borderRadius: 10, background: '#0a0a0a' }} />
          suresigncontracts.app
        </div>
      </div>
    ),
    size,
  );
}
