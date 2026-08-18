'use client';

// Super Admin ONLY — a manual test bench for every toast variant/option the
// app can produce via `@/lib/toast` (see that module for the actual SureSign
// branding these all share). Exists purely so a real toast can be reviewed
// live in the running app without wiring a temporary call into some other
// page's workflow. No backend endpoint, no persisted state — everything
// here is client-side only.
import { CheckCircle2, XCircle, AlertTriangle, Info, Bell, Clock, Type } from 'lucide-react';
import toast from '@/lib/toast';

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

export default function ToastPreviewPanel() {
  return (
    <div className="space-y-6">
      <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
        Fires a real toast using the exact same branded wrapper every page in the app calls
        (<code>@/lib/toast</code>) — nothing here is a mockup.
      </p>

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
    </div>
  );
}
