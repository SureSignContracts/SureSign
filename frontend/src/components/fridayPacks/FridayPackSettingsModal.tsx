'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { X, Plus, Trash2 } from 'lucide-react';
import api from '@/lib/api';
import toast from '@/lib/toast';
import { getErrorMessage } from '@/lib/getErrorMessage';
import Checkbox from '@/components/ui/Checkbox';
import Select from '@/components/ui/Select';
import { useAuthStore } from '@/store/authStore';

/**
 * Automated Friday Pack, V1B/V1E — project settings: Enabled + Included
 * Sections + (V1E) opt-in Automatic Draft Generation + local generation
 * hour. Recipients/delivery mode/reviewer/approver/timezone selection
 * are deliberately NOT shown here (later phases, per the V1E spec — the
 * organisation timezone is shown as display-only context, never
 * selectable).
 *
 * R1A — Friday Pack Realignment (2026-08-31): section keys/labels below
 * were realigned to the authentic construction Friday Pack structure
 * (mirrors backend App\Support\FridayPack\FridayPackSections exactly —
 * see that class's docblock for the full rationale). The previous
 * management-report keys (programme, site_reports, risks, rfis,
 * variations, commercial, delays_eot, meetings_actions,
 * delivery_documents, drawings, qa_snagging, upcoming_actions,
 * executive_summary, progress) are no longer selectable here.
 */
const SECTION_LABELS: Record<string, string> = {
  report_information: 'Report Information',
  weekly_summary: 'Weekly Summary',
  workforce: 'Workforce on Site',
  site_photographs: 'Site Photographs',
  rams: 'RAMS in Use',
  toolbox_talks: 'Toolbox Talks / Briefings',
  site_inductions: 'Site Inductions',
  incidents: 'Accidents / Incidents / Near Misses',
  hs_inspections: 'H&S Inspections',
  permits_inspections: 'Permits to Work & Statutory Inspections',
  plant_equipment: 'Plant & Equipment on Site',
  materials_delivered: 'Materials Delivered',
  site_issues: 'Site Issues, Delays & Risks',
  look_ahead: "Look Ahead: Next Week's Programme",
  sign_off: 'Sign Off',
};

/** Only whole hours — the scheduler infrastructure runs on an hourly UTC
 * tick, so offering minute-level precision would promise something it
 * cannot actually honour. */
const HOUR_OPTIONS = Array.from({ length: 24 }, (_, h) => h);

function formatHour(hour: number): string {
  const period = hour < 12 ? 'AM' : 'PM';
  const displayHour = hour % 12 === 0 ? 12 : hour % 12;
  return `${displayHour}:00 ${period}`;
}

interface FridayPackRecipient {
  name?: string | null;
  email: string;
}

interface FridayPackSettingsData {
  enabled: boolean;
  included_sections: string[];
  automatic_generation_enabled: boolean;
  generation_hour_local: number;
  recipients?: FridayPackRecipient[] | null;
}

/**
 * Mounts only once `data` has actually resolved — its form state is
 * initialized directly from `data` (a lazy useState initializer), never
 * synced in afterward via useEffect. Avoids react-hooks/set-state-in-effect
 * entirely rather than suppressing it.
 */
function FridayPackSettingsForm({ projectId, data, onClose }: { projectId: string; data: FridayPackSettingsData; onClose: () => void }) {
  const qc = useQueryClient();
  const orgTimezone = useAuthStore.getState().user?.organization?.timezone ?? 'Europe/London';
  const [enabled, setEnabled] = useState(() => !!data.enabled);
  const [sections, setSections] = useState<string[]>(() =>
    Array.isArray(data.included_sections) ? data.included_sections : Object.keys(SECTION_LABELS));
  const [automationEnabled, setAutomationEnabled] = useState(() => !!data.automatic_generation_enabled);
  const [generationHour, setGenerationHour] = useState(() => data.generation_hour_local ?? 15);
  const [recipients, setRecipients] = useState<FridayPackRecipient[]>(() =>
    Array.isArray(data.recipients) ? data.recipients.map(r => ({ name: r.name ?? '', email: r.email })) : []);

  const mutation = useMutation({
    mutationFn: () => api.put(`/projects/${projectId}/friday-pack-settings`, {
      enabled, included_sections: sections,
      automatic_generation_enabled: automationEnabled, generation_hour_local: generationHour,
      recipients: recipients.filter(r => r.email.trim() !== ''),
    }).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['friday-pack-settings', projectId] });
      toast.success('Friday Pack settings saved');
      onClose();
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to save settings')),
  });

  const toggleSection = (key: string) => {
    setSections(prev => prev.includes(key) ? prev.filter(s => s !== key) : [...prev, key]);
  };

  return (
    <>
      <div className="p-6 space-y-5 max-h-[70vh] overflow-y-auto">
        <Checkbox
          checked={enabled}
          onChange={setEnabled}
          label={<span style={{ color: 'var(--text-primary)' }}>Enabled for this project</span>}
          className="flex items-center gap-2 text-sm"
        />
        <div>
          <p className="text-xs font-medium mb-2" style={{ color: 'var(--text-muted)' }}>Included Sections</p>
          <div className="grid grid-cols-2 gap-2">
            {Object.entries(SECTION_LABELS).map(([key, label]) => (
              <Checkbox
                key={key}
                checked={sections.includes(key)}
                onChange={() => toggleSection(key)}
                label={<span className="text-sm" style={{ color: 'var(--text-secondary)' }}>{label}</span>}
                className="flex items-center gap-2"
              />
            ))}
          </div>
        </div>

        <div className="pt-2" style={{ borderTop: '1px solid var(--border)' }}>
          <Checkbox
            checked={automationEnabled}
            onChange={setAutomationEnabled}
            label={<span style={{ color: 'var(--text-primary)' }}>Automatic Draft Generation</span>}
            className="flex items-center gap-2 text-sm mt-4 mb-2"
          />
          <p className="text-xs mb-3" style={{ color: 'var(--text-muted)' }}>
            Automatically generate a Draft every Friday at {formatHour(generationHour)}. Timezone: {orgTimezone}.
            A human must still review and approve it — nothing is submitted, approved, or sent automatically.
          </p>
          <div className={automationEnabled ? '' : 'opacity-50'}>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Generation Time</label>
            <Select
              value={String(generationHour)}
              onChange={e => setGenerationHour(Number(e.target.value))}
              disabled={!automationEnabled}
              className="w-full max-w-[180px]"
            >
              {HOUR_OPTIONS.map(h => <option key={h} value={h}>{formatHour(h)}</option>)}
            </Select>
          </div>
        </div>

        <div className="pt-2" style={{ borderTop: '1px solid var(--border)' }}>
          <p className="text-sm font-medium mt-4 mb-1" style={{ color: 'var(--text-primary)' }}>Delivery Recipients</p>
          <p className="text-xs mb-3" style={{ color: 'var(--text-muted)' }}>
            Who receives an approved Friday Pack when you explicitly select Send. Adding or removing a
            recipient here never affects a delivery already sent.
          </p>
          <div className="space-y-2">
            {recipients.map((r, i) => (
              <div key={i} className="flex items-center gap-2">
                <input
                  type="text"
                  placeholder="Name (optional)"
                  value={r.name ?? ''}
                  onChange={e => setRecipients(prev => prev.map((p, idx) => idx === i ? { ...p, name: e.target.value } : p))}
                  className="w-1/3 px-3 py-2 rounded-lg text-sm"
                  style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-primary)', border: '1px solid var(--border)' }}
                />
                <input
                  type="email"
                  placeholder="Email address"
                  value={r.email}
                  onChange={e => setRecipients(prev => prev.map((p, idx) => idx === i ? { ...p, email: e.target.value } : p))}
                  className="flex-1 px-3 py-2 rounded-lg text-sm"
                  style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-primary)', border: '1px solid var(--border)' }}
                />
                <button
                  type="button"
                  onClick={() => setRecipients(prev => prev.filter((_, idx) => idx !== i))}
                  className="p-2 rounded-lg hover:bg-[var(--bg-hover)]"
                  aria-label="Remove recipient"
                >
                  <Trash2 size={14} style={{ color: 'var(--text-muted)' }} />
                </button>
              </div>
            ))}
          </div>
          <button
            type="button"
            onClick={() => setRecipients(prev => [...prev, { name: '', email: '' }])}
            className="mt-2 flex items-center gap-1 text-xs font-medium"
            style={{ color: 'var(--gold)' }}
          >
            <Plus size={14} /> Add recipient
          </button>
        </div>

        {mutation.isError && (
          <p className="text-xs text-red-400">{getErrorMessage(mutation.error, 'Failed to save. Please try again.')}</p>
        )}
      </div>
      <div className="flex justify-end gap-3 px-6 pb-6">
        <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
        <button
          type="button"
          disabled={mutation.isPending}
          onClick={() => mutation.mutate()}
          className="px-4 py-2 rounded-lg text-sm font-medium transition-all hover:opacity-90 active:scale-[0.98] disabled:opacity-60"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          {mutation.isPending ? 'Saving…' : 'Save Settings'}
        </button>
      </div>
    </>
  );
}

export default function FridayPackSettingsModal({ projectId, onClose }: { projectId: string; onClose: () => void }) {
  const { data, isLoading } = useQuery<FridayPackSettingsData>({
    queryKey: ['friday-pack-settings', projectId],
    queryFn: () => api.get(`/projects/${projectId}/friday-pack-settings`).then(r => r.data),
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="ss-animate-in w-full max-w-lg rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        <div className="flex items-center justify-between px-6 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Friday Pack Settings</h2>
          <button onClick={onClose} className="p-1 rounded-lg hover:bg-[var(--bg-hover)]">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>
        {isLoading || !data ? (
          <div className="p-6 space-y-2">
            {[...Array(4)].map((_, i) => <div key={i} className="h-8 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />)}
          </div>
        ) : (
          <FridayPackSettingsForm projectId={projectId} data={data} onClose={onClose} />
        )}
      </div>
    </div>
  );
}
