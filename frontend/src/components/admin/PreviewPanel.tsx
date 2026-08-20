'use client';

// Super Admin ONLY — a general manual test bench, not scoped to any one
// feature. Started as a toast-only test bench; now holds one section per
// thing worth eyeballing live in the running app without wiring a
// temporary call into some other page's real workflow. No backend
// endpoint, no persisted state — everything here is client-side only.
// Add new sections here rather than starting a second preview page.
import { useState } from 'react';
import {
  CheckCircle2, XCircle, AlertTriangle, Info, Bell, Clock, Type, X,
  Contrast, Sparkles, TreePine, Ghost, PartyPopper, Heart, Egg,
} from 'lucide-react';
import toast from '@/lib/toast';
import SureSignLoader, { ACCENT_STYLE_LABELS, type AccentStyle } from '@/components/ui/SureSignLoader';
import { LOADER_EXIT_MS } from '@/hooks/useAuthSplash';
import { useNotificationSound } from '@/hooks/useNotificationSound';

const ACCENT_ICONS: Record<AccentStyle, React.ComponentType<{ size?: number }>> = {
  monochrome: Contrast,
  mint: Sparkles,
  christmas: TreePine,
  halloween: Ghost,
  new_year: PartyPopper,
  valentines: Heart,
  easter: Egg,
};

const ACCENT_OPTIONS = (Object.keys(ACCENT_STYLE_LABELS) as AccentStyle[]).map((value) => ({
  value,
  label: ACCENT_STYLE_LABELS[value],
  icon: ACCENT_ICONS[value],
}));

const ACCENT_SWATCHES: Record<AccentStyle, string> = {
  monochrome: 'var(--text-primary)',
  mint: '#74ba8a',
  christmas: '#c94d43',
  halloween: '#d87b37',
  new_year: '#c69b42',
  valentines: '#d85d7c',
  easter: '#9985c4',
};

function Section({ title, description, children }: { title: string; description: string; children: React.ReactNode }) {
  return (
    <section className="space-y-4">
      <div>
        <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{title}</h3>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>{description}</p>
      </div>
      {children}
    </section>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-2">
      <p className="text-xs font-semibold" style={{ color: 'var(--text-secondary)' }}>{label}</p>
      <div className="flex flex-wrap gap-2">{children}</div>
    </div>
  );
}

function Btn({ onClick, icon: Icon, children }: { onClick: () => void; icon: React.ComponentType<{ size?: number }>; children: React.ReactNode }) {
  return (
    <button
      onClick={onClick}
      className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium transition-colors"
      style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
    >
      <Icon size={14} />
      {children}
    </button>
  );
}

function LoaderPreviewSection() {
  const [visible, setVisible] = useState(false);
  const [exiting, setExiting] = useState(false);
  const [accent, setAccent] = useState<AccentStyle>('monochrome');

  function play(style: AccentStyle) {
    setAccent(style);
    setExiting(false);
    setVisible(true);
  }

  function close() {
    // Real exit sequence — same as useAuthSplash: GSAP fade/scale-out via
    // `exiting`, then unmount after the same LOADER_EXIT_MS hold the real
    // auth flow uses, not an instant cut.
    setExiting(true);
    window.setTimeout(() => setVisible(false), LOADER_EXIT_MS);
  }

  return (
    <Section
      title="Global loading screen"
      description="Plays the real SureSignLoader component the app shows while auth/session state resolves (admin/app/dashboard layouts) — same entrance, mark reveal, and exit sequence useAuthSplash drives, not a mockup. Loops the reveal here (real usage never does) so you can watch the draw-in repeatedly — close it whenever you're done. Every style here is also selectable for real under the Branding tab's Accent setting."
    >
      <div
        className="grid overflow-hidden rounded-xl border sm:grid-cols-2 lg:grid-cols-4"
        style={{ borderColor: 'var(--border)', backgroundColor: 'var(--bg-elevated)' }}
        aria-label="Loading screen themes"
      >
        {ACCENT_OPTIONS.map((o) => (
          <button
            key={o.value}
            type="button"
            onClick={() => play(o.value)}
            className="group relative flex min-h-14 items-center gap-3 border-b border-r px-3.5 text-left transition-colors duration-200 last:border-b-0 hover:bg-[var(--bg-hover)] focus-visible:z-10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--text-primary)] active:bg-[var(--bg-hover)] sm:[&:nth-last-child(-n+2)]:border-b-0 lg:border-b-0"
            style={{ borderColor: 'var(--border)', color: 'var(--text-primary)' }}
          >
            <span className="relative flex h-7 w-7 shrink-0 items-center justify-center">
              <span className="absolute inset-0 rotate-45 rounded-[7px] opacity-15 transition-transform duration-300 group-hover:rotate-[135deg]" style={{ backgroundColor: ACCENT_SWATCHES[o.value] }} />
              <span className="relative" style={{ color: ACCENT_SWATCHES[o.value] }}>
                <o.icon size={15} />
              </span>
            </span>
            <span className="text-xs font-medium">{o.label}</span>
            <span className="ml-auto h-px w-0 transition-all duration-300 group-hover:w-4" style={{ backgroundColor: ACCENT_SWATCHES[o.value] }} />
          </button>
        ))}
      </div>
      {visible && (
        <div className="fixed inset-0 z-[999]">
          <SureSignLoader exiting={exiting} loop accent={accent} />
          <button
            onClick={close}
            aria-label="Close loader preview"
            className="fixed right-5 top-5 z-[1000] flex h-9 w-9 items-center justify-center rounded-full transition-colors hover:bg-[var(--bg-hover)]"
            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
          >
            <X size={16} />
          </button>
        </div>
      )}
    </Section>
  );
}

function ToastPreviewSection() {
  return (
    <Section
      title="Toasts"
      description="Fires a real toast using the exact same branded wrapper every page in the app calls (@/lib/toast)."
    >
      <Row label="Basic variants">
        <Btn icon={Bell} onClick={() => toast('Heads up.')}>
          Default
        </Btn>
        <Btn icon={CheckCircle2} onClick={() => toast.success('Team updated.')}>Success</Btn>
        <Btn icon={XCircle} onClick={() => toast.error('Could not save changes.')}>Error</Btn>
        <Btn icon={AlertTriangle} onClick={() => toast.warning('Approaching your monthly limit.')}>Warning</Btn>
        <Btn icon={Info} onClick={() => toast.info('A new release is available.')}>Info</Btn>
      </Row>

      <Row label="With description">
        <Btn
          icon={CheckCircle2}
          onClick={() => toast.success('Contract analysis complete.', {
            description: 'Key terms, payment rules, and programme milestones were extracted. Review and confirm to apply them.',
          })}
        >
          Success + description
        </Btn>
        <Btn
          icon={AlertTriangle}
          onClick={() => toast.warning('AI usage nearing your monthly limit.', {
            description: "You've used 82% of this organisation's AI analyses for this billing period.",
          })}
        >
          Warning + description
        </Btn>
        <Btn
          icon={XCircle}
          onClick={() => toast.error('Contract analysis failed.', {
            description: 'The document could not be parsed. Try re-uploading it, or contact support if this keeps happening.',
          })}
        >
          Error + description
        </Btn>
      </Row>

      <Row label="With an action button">
        <Btn
          icon={CheckCircle2}
          onClick={() => toast.success('Contract analysis complete.', {
            description: 'Review the extracted terms before they’re applied to this contract.',
            action: { label: 'View contract', onClick: () => {} },
          })}
        >
          Success + action
        </Btn>
      </Row>

      <Row label="Promise (loading → success/error morph)">
        <Btn
          icon={Clock}
          onClick={() => {
            const fakeRequest = new Promise((resolve) => setTimeout(resolve, 2000));
            toast.promise(fakeRequest, {
              loading: 'Analysing contract…',
              success: 'Contract analysis complete.',
              error: 'Contract analysis failed.',
            });
          }}
        >
          Promise → success (2s)
        </Btn>
        <Btn
          icon={Clock}
          onClick={() => {
            const fakeRequest = new Promise((_resolve, reject) => setTimeout(reject, 2000));
            toast.promise(fakeRequest, {
              loading: 'Analysing contract…',
              success: 'Contract analysis complete.',
              error: 'Contract analysis failed.',
            });
          }}
        >
          Promise → error (2s)
        </Btn>
      </Row>

      <Row label="Update in place">
        <Btn
          icon={Type}
          onClick={() => {
            const id = toast('Uploading document…', { duration: 100000 });
            setTimeout(() => {
              toast.update(id, { title: 'Document uploaded.', type: 'success' });
            }, 1500);
          }}
        >
          Start → update after 1.5s
        </Btn>
      </Row>
    </Section>
  );
}

function NotificationSoundPreviewSection() {
  const { playTestSound, hasSoundConfigured } = useNotificationSound();

  return (
    <Section
      title="Notification sound"
      description="Plays the real, currently-configured notification sound (Branding tab) using the exact same playback path a genuine new notification uses — not a mockup. Configure or replace the asset there; this only previews it."
    >
      {hasSoundConfigured ? (
        <Row label="Play">
          <Btn icon={Bell} onClick={playTestSound}>Play notification sound</Btn>
        </Row>
      ) : (
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
          No notification sound has been uploaded yet — configure one under the Branding tab first.
        </p>
      )}
    </Section>
  );
}

export default function PreviewPanel() {
  return (
    <div className="space-y-10">
      <LoaderPreviewSection />
      <div style={{ borderTop: '1px solid var(--border)' }} />
      <ToastPreviewSection />
      <div style={{ borderTop: '1px solid var(--border)' }} />
      <NotificationSoundPreviewSection />
    </div>
  );
}
