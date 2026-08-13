'use client';

import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { X } from 'lucide-react';
import Button from '@/components/ui/Button';
import { normalizeApiError } from '@/lib/normalizeApiError';
import Select from '@/components/ui/Select';
import { PROJECT_ORGANIZATION_ROLE_OPTIONS } from '@/lib/projectOrganizationRole';
import { SUPPORTED_CURRENCIES } from '@/lib/currency';
import { useAuthStore } from '@/store/authStore';

// Mirrors the identical list in frontend/src/app/app/projects/page.tsx's
// CreateProjectModal — kept as two small literal arrays (not centralised)
// since it's a fixed 6-item list with no separate label mapping, unlike
// the Project Organization Role options above.
const CONTRACT_TYPES = ['JCT', 'NEC3', 'NEC4', 'FIDIC', 'Bespoke', 'Other'];

/**
 * Project Editing — the counterpart to CreateProjectModal
 * (frontend/src/app/app/projects/page.tsx) for a project that already
 * exists. Phase B moved description/status/contract type/contract value/
 * currency/dates here from the Create Project form (now trimmed to
 * "Project Basics") specifically so nothing already supported by the
 * platform became inaccessible after creation — this is not a Project
 * Settings redesign, and still never touches commercial/contract-derived/
 * AI fields (those remain Contract-level, not Project-level, concepts).
 */

export type EditableProject = {
  id: number;
  name: string;
  code: string | null;
  organization_role: string | null;
  description: string | null;
  status: string;
  contract_type: string | null;
  contract_value: number | string | null;
  currency: string | null;
  // Phase E — added here specifically so a Project Suggestions apply of
  // retention_percentage is never a hidden, one-way, un-editable field;
  // the backend has always validated it (ProjectController::store()/
  // update() already accepted it before this phase), only this modal was
  // missing it.
  retention_percentage: number | string | null;
  start_date: string | null;
  end_date: string | null;
  address: string | null;
  city: string | null;
  state: string | null;
  postcode: string | null;
  country: string | null;
  latitude: number | string | null;
  longitude: number | string | null;
};

type FormState = {
  name: string; code: string; organization_role: string;
  description: string; status: string; contract_type: string; contract_value: string; currency: string;
  retention_percentage: string;
  start_date: string; end_date: string;
  address: string; city: string; state: string; postcode: string; country: string;
  latitude: string; longitude: string;
};

function toFormState(project: EditableProject): FormState {
  return {
    name: project.name ?? '',
    code: project.code ?? '',
    organization_role: project.organization_role ?? '',
    description: project.description ?? '',
    status: project.status ?? 'active',
    contract_type: project.contract_type ?? '',
    contract_value: project.contract_value !== null && project.contract_value !== undefined ? String(project.contract_value) : '',
    currency: project.currency ?? '',
    retention_percentage: project.retention_percentage !== null && project.retention_percentage !== undefined ? String(project.retention_percentage) : '',
    start_date: project.start_date ?? '',
    end_date: project.end_date ?? '',
    address: project.address ?? '',
    city: project.city ?? '',
    state: project.state ?? '',
    postcode: project.postcode ?? '',
    country: project.country ?? '',
    latitude: project.latitude !== null && project.latitude !== undefined ? String(project.latitude) : '',
    longitude: project.longitude !== null && project.longitude !== undefined ? String(project.longitude) : '',
  };
}

const INPUT_CLS = 'w-full rounded-lg px-3 py-2 text-sm outline-none border border-[var(--border)] focus:border-[var(--gold)] transition-colors duration-200';
const labelStyle = { color: 'var(--text-muted)', fontSize: '0.75rem', marginBottom: '4px', display: 'block' } as const;
const inputStyle = { backgroundColor: 'var(--bg-elevated)', color: 'var(--text-primary)' } as const;

export default function EditProjectModal({ project, projectId, onClose }: {
  project: EditableProject;
  /** The route param string (e.g. from useParams()) — passed explicitly
   *  because the Overview page's own `['project', id]` query key is built
   *  from that string, while `project.id` here is a number from the API
   *  response. React Query hashes keys by JSON serialisation, so `1` and
   *  `'1'` are NOT the same key — invalidating with the wrong type silently
   *  misses the query and the page never refetches after save. */
  projectId: string;
  onClose: () => void;
}) {
  const queryClient = useQueryClient();
  const { user } = useAuthStore();
  const [form, setForm] = useState<FormState>(() => toFormState(project));
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  const set = (k: keyof FormState, v: string) => setForm(f => ({ ...f, [k]: v }));

  const mutation = useMutation({
    mutationFn: () => api.put(`/projects/${project.id}`, {
      name: form.name,
      code: form.code || null,
      // '' means "clear back to not set" — sent explicitly as null so the
      // backend's omit-vs-null-clear distinction (organization_role/currency
      // both use `sometimes|nullable`) resolves to a real clear, not a no-op.
      organization_role: form.organization_role || null,
      description: form.description || null,
      status: form.status,
      contract_type: form.contract_type || null,
      contract_value: form.contract_value === '' ? null : form.contract_value,
      currency: form.currency || null,
      retention_percentage: form.retention_percentage === '' ? null : form.retention_percentage,
      start_date: form.start_date || null,
      end_date: form.end_date || null,
      address: form.address || null,
      city: form.city || null,
      state: form.state || null,
      postcode: form.postcode || null,
      country: form.country || null,
      // Empty means "clear" (stored as null) — never 0, which is a real
      // coordinate. Clearing both is how a user removes a project from the
      // Dashboard Project Map.
      latitude: form.latitude === '' ? null : form.latitude,
      longitude: form.longitude === '' ? null : form.longitude,
    }).then(r => r.data),
    onSuccess: () => {
      // Only the queries a Project edit can actually affect — never a
      // blanket invalidation. The Dashboard's project_map/needs_attention
      // block lives under the same 'dashboard-action-centre' key it always
      // has, so this is what lets an updated/cleared coordinate reach the
      // map without a hard refresh.
      queryClient.invalidateQueries({ queryKey: ['project', projectId] });
      queryClient.invalidateQueries({ queryKey: ['projects-portfolio'] });
      queryClient.invalidateQueries({ queryKey: ['dashboard-action-centre'] });
      onClose();
    },
    onError: (e: unknown) => {
      const normalized = normalizeApiError(e, 'Failed to update project. Please check all fields.');
      setFieldErrors(normalized.fieldErrors ?? {});
      setError(normalized.type === 'validation' ? 'Check the highlighted information.' : normalized.message);
    },
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.55)' }}>
      <div
        className="ss-animate-in w-full max-w-2xl rounded-2xl flex flex-col max-h-[90vh]"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
      >
        <div className="flex items-center justify-between px-6 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Edit project</h2>
          <button onClick={onClose} className="p-1.5 rounded-lg hover:bg-[var(--bg-hover)] transition-colors">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        <div className="overflow-y-auto flex-1 px-6 py-5 space-y-4">
          {error && (
            <div className="px-4 py-3 rounded-lg text-sm" style={{ backgroundColor: 'rgba(239,68,68,0.1)', color: '#ef4444' }}>
              {error}
            </div>
          )}

          <div>
            <p className="text-sm font-medium mb-3" style={{ color: 'var(--text-primary)' }}>Project Details</p>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label style={labelStyle}>Project Name *</label>
                <input
                  className={INPUT_CLS} style={inputStyle} value={form.name} onChange={e => set('name', e.target.value)}
                  aria-invalid={fieldErrors.name ? true : undefined}
                  aria-describedby={fieldErrors.name ? 'edit-project-name-error' : undefined}
                />
                {fieldErrors.name && (
                  <p id="edit-project-name-error" className="text-xs mt-1" style={{ color: '#f87171' }}>{fieldErrors.name[0]}</p>
                )}
              </div>
              <div>
                <label style={labelStyle}>Project Number / Code</label>
                <input className={INPUT_CLS} style={inputStyle} value={form.code} onChange={e => set('code', e.target.value)} />
              </div>
            </div>
            <div className="mt-4">
              <label style={labelStyle}>Your organization&rsquo;s role on this project</label>
              <Select className="w-full" value={form.organization_role} onChange={e => set('organization_role', e.target.value)}>
                <option value="">Role not set</option>
                {PROJECT_ORGANIZATION_ROLE_OPTIONS.map(({ value, label }) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </Select>
              <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
                Tell SureSign how your organization is acting on this project. This can differ between projects.
              </p>
            </div>
            <div className="mt-4">
              <label style={labelStyle}>Description</label>
              <textarea
                rows={3}
                className={INPUT_CLS}
                style={{ ...inputStyle, resize: 'vertical' }}
                value={form.description}
                onChange={e => set('description', e.target.value)}
                placeholder="Brief project overview…"
              />
            </div>
          </div>

          <div className="pt-2" style={{ borderTop: '1px solid var(--border)' }}>
            <p className="text-sm font-medium mb-3" style={{ color: 'var(--text-primary)' }}>Contract &amp; Status</p>
            <div className="space-y-3">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label style={labelStyle}>Contract Type</label>
                  <Select className="w-full" value={form.contract_type} onChange={e => set('contract_type', e.target.value)}>
                    <option value="">Select contract type…</option>
                    {CONTRACT_TYPES.map(t => <option key={t} value={t}>{t}</option>)}
                  </Select>
                </div>
                <div>
                  <label style={labelStyle}>Contract Value</label>
                  <input
                    className={INPUT_CLS}
                    style={inputStyle}
                    type="number"
                    min="0"
                    step="0.01"
                    value={form.contract_value}
                    onChange={e => set('contract_value', e.target.value)}
                    placeholder="0.00"
                    aria-invalid={fieldErrors.contract_value ? true : undefined}
                    aria-describedby={fieldErrors.contract_value ? 'edit-project-contract-value-error' : undefined}
                  />
                  {fieldErrors.contract_value && (
                    <p id="edit-project-contract-value-error" className="text-xs mt-1" style={{ color: '#f87171' }}>{fieldErrors.contract_value[0]}</p>
                  )}
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label style={labelStyle}>Currency</label>
                  <Select className="w-full" value={form.currency} onChange={e => set('currency', e.target.value)}>
                    <option value="">Use organisation default — {user?.organization?.effective_currency ?? 'GBP'}</option>
                    {SUPPORTED_CURRENCIES.map(code => (
                      <option key={code} value={code}>{code}</option>
                    ))}
                  </Select>
                </div>
                <div>
                  <label style={labelStyle}>Status</label>
                  <Select className="w-full" value={form.status} onChange={e => set('status', e.target.value)}>
                    <option value="active">Active</option>
                    <option value="on_hold">On Hold</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                  </Select>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label style={labelStyle}>Start Date</label>
                  <input className={INPUT_CLS} style={inputStyle} type="date" value={form.start_date} onChange={e => set('start_date', e.target.value)} />
                </div>
                <div>
                  <label style={labelStyle}>Completion Date</label>
                  <input className={INPUT_CLS} style={inputStyle} type="date" value={form.end_date} onChange={e => set('end_date', e.target.value)} />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label style={labelStyle}>Retention %</label>
                  <input
                    className={INPUT_CLS} style={inputStyle} type="number" min="0" max="100" step="0.01"
                    value={form.retention_percentage} onChange={e => set('retention_percentage', e.target.value)}
                    placeholder="0.00"
                  />
                </div>
              </div>
            </div>
          </div>

          <div className="pt-2" style={{ borderTop: '1px solid var(--border)' }}>
            <p className="text-sm font-medium mb-3" style={{ color: 'var(--text-primary)' }}>Project Location</p>
            <div className="space-y-3">
              <div>
                <label style={labelStyle}>Address</label>
                <input className={INPUT_CLS} style={inputStyle} value={form.address} onChange={e => set('address', e.target.value)} placeholder="Street address" />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label style={labelStyle}>City</label>
                  <input className={INPUT_CLS} style={inputStyle} value={form.city} onChange={e => set('city', e.target.value)} />
                </div>
                <div>
                  <label style={labelStyle}>State / Region</label>
                  <input className={INPUT_CLS} style={inputStyle} value={form.state} onChange={e => set('state', e.target.value)} />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label style={labelStyle}>Postcode / ZIP</label>
                  <input className={INPUT_CLS} style={inputStyle} value={form.postcode} onChange={e => set('postcode', e.target.value)} />
                </div>
                <div>
                  <label style={labelStyle}>Country</label>
                  <input className={INPUT_CLS} style={inputStyle} value={form.country} onChange={e => set('country', e.target.value)} placeholder="e.g. United Kingdom" />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label style={labelStyle}>Latitude</label>
                  <input
                    className={INPUT_CLS} style={inputStyle} type="number" step="any"
                    value={form.latitude} onChange={e => set('latitude', e.target.value)} placeholder="e.g. 51.5074"
                    aria-invalid={fieldErrors.latitude ? true : undefined}
                    aria-describedby={fieldErrors.latitude ? 'edit-project-latitude-error' : undefined}
                  />
                  {fieldErrors.latitude && (
                    <p id="edit-project-latitude-error" className="text-xs mt-1" style={{ color: '#f87171' }}>{fieldErrors.latitude[0]}</p>
                  )}
                </div>
                <div>
                  <label style={labelStyle}>Longitude</label>
                  <input
                    className={INPUT_CLS} style={inputStyle} type="number" step="any"
                    value={form.longitude} onChange={e => set('longitude', e.target.value)} placeholder="e.g. -0.1278"
                    aria-invalid={fieldErrors.longitude ? true : undefined}
                    aria-describedby={fieldErrors.longitude ? 'edit-project-longitude-error' : undefined}
                  />
                  {fieldErrors.longitude && (
                    <p id="edit-project-longitude-error" className="text-xs mt-1" style={{ color: '#f87171' }}>{fieldErrors.longitude[0]}</p>
                  )}
                </div>
              </div>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                Optional. Used to position this project on the organisation Project Map. Clear both fields to remove this project from the map.
              </p>
            </div>
          </div>
        </div>

        <div className="flex items-center justify-end gap-3 px-6 py-4" style={{ borderTop: '1px solid var(--border)' }}>
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
          <Button
            onClick={() => { setError(null); setFieldErrors({}); mutation.mutate(); }}
            disabled={!form.name || mutation.isPending}
          >
            {mutation.isPending ? 'Saving…' : 'Save Changes'}
          </Button>
        </div>
      </div>
    </div>
  );
}
