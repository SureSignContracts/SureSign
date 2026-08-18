'use client';
import FeatureAvailabilityGate from '@/components/feature-availability/FeatureAvailabilityGate';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { effectiveTodayYmd } from '@/lib/dateTime';
import { MessageSquare, Plus, Search, X } from 'lucide-react';
import toast from '@/lib/toast';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import PromptActionButton from '@/components/prompts/PromptActionButton';
import PageTourButton from '@/components/tours/PageTourButton';
import { ProjectModuleHeader, ProjectModuleMetric } from '@/components/projects/ProjectModuleHeader';
import Button from '@/components/ui/Button';
import { getErrorMessage } from '@/lib/getErrorMessage';
import EvidenceSection from '@/components/documents/EvidenceSection';
import DrawingLocationsSection from '@/components/drawings/DrawingLocationsSection';
import NewRfiModal from '@/components/rfis/NewRfiModal';

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  open:             { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  pending_response: { bg: 'rgba(249,115,22,0.12)', text: '#fb923c' },
  responded:        { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  closed:           { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  draft:            { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
};

const RFI_STATUS_LABELS: Record<string, string> = {
  open:             'Open',
  pending_response: 'Pending Response',
  responded:        'Responded',
  closed:           'Closed',
  draft:            'Draft',
};

const PRIORITY_LABELS: Record<string, string> = {
  urgent: 'Urgent',
  high:   'High',
  normal: 'Normal',
  low:    'Low',
};

// ─── RFI Response Modal ────────────────────────────────────────────────────────

function RfiResponseModal({ rfi, projectId, onClose }: { rfi: any; projectId: string; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({
    response:       rfi.response ?? '',
    responded_at:   rfi.responded_at ? String(rfi.responded_at).slice(0, 10) : effectiveTodayYmd(),
  });

  const { mutate, isPending } = useMutation({
    mutationFn: (data: typeof form) =>
      api.put(`/rfis/${rfi.id}`, { ...data, status: 'responded' }).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-rfis', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      toast.success('Response recorded');
      onClose();
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to record response')),
  });

  const inputStyle = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };
  const labelStyle = { color: 'var(--text-muted)' };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="ss-animate-in w-full max-w-md rounded-2xl" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Respond to RFI</h2>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>#{rfi.rfi_number} — {rfi.subject}</p>
          </div>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutate(form); }} className="p-5 space-y-4">
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Response *</label>
            <textarea value={form.response} onChange={e => setForm(p => ({ ...p, response: e.target.value }))} required rows={5}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          {/* "Assigned to" removed — rfis.assigned_to is a real FK to
              users.id, but this form only ever had a free-text name field
              for it, which the backend now correctly rejects instead of
              silently discarding or crashing. Needs a user-picker before
              this can come back. */}
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Response date</label>
            <input type="date" value={form.responded_at} onChange={e => setForm(p => ({ ...p, responded_at: e.target.value }))}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <EvidenceSection
            attachmentsUrl={`/rfis/${rfi.id}/attachments`}
            queryKey={['rfi-attachments', rfi.id]}
            label="Supporting documents"
          />
          <DrawingLocationsSection projectId={projectId} type="rfi" recordId={rfi.id} />
          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={isPending} className="px-4 py-2 rounded-lg text-sm font-medium transition-all active:scale-[0.98]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>
              {isPending ? 'Saving…' : 'Record Response'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function ProjectRfisPage() {
  const { id } = useParams<{ id: string }>();
  const { canManageRfis: canWrite } = useProjectPermissions();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [showModal, setShowModal] = useState(false);
  const [respondRfi, setRespondRfi] = useState<any | null>(null);
  const queryClient = useQueryClient();

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['project-rfis', id],
    queryFn: () => api.get(`/projects/${id}/rfis`).then(r => r.data),
  });

  const rfis = (data?.data ?? []).filter((r: any) => {
    const matchSearch = r.subject?.toLowerCase().includes(search.toLowerCase()) || String(r.rfi_number).includes(search);
    const matchStatus = statusFilter === 'all' || r.status === statusFilter;
    return matchSearch && matchStatus;
  });

  const openCount  = (data?.data ?? []).filter((r: any) => r.status === 'open').length;
  const pendingCount = (data?.data ?? []).filter((r: any) => r.status === 'pending_response').length;

  const { mutate: closeRfi } = useMutation({
    mutationFn: (rfi: any) => api.put(`/rfis/${rfi.id}`, { status: 'closed' }).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-rfis', id] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', id] });
      toast.success('RFI closed');
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to close RFI')),
  });

  return (
    <div className="ss-projects-page mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">
      <ProjectModuleHeader
        category="Project communication"
        title="RFIs"
        description="Raise, route and close requests for information without losing the response trail."
        icon={MessageSquare}
        metricColumns={3}
        tour={<PageTourButton tourKey="page-rfis" label="Take a tour of this page" />}
        action={canWrite ? (
          <button data-tour="rfis-new" onClick={() => setShowModal(true)} className="flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-[#9ee5b5] px-5 text-sm font-semibold text-[#18211d] transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#b4edc6] active:translate-y-0">
            <Plus size={16} /> New RFI
          </button>
        ) : undefined}
      >
        {[
          { label: 'Total', value: (data?.data ?? []).length, color: 'var(--gold)' },
          { label: 'Open', value: openCount, color: '#facc15' },
          { label: 'Pending Response', value: pendingCount, color: '#fb923c' },
        ].map((s, index) => (
          <ProjectModuleMetric key={s.label} label={s.label} value={s.value} tone={s.color} index={index} />
        ))}
      </ProjectModuleHeader>

      {/* Filters */}
      <div className="flex gap-3 flex-wrap" data-tour="rfis-filters">
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search RFIs…"
            className="pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)', minWidth: '220px', boxShadow: 'var(--shadow-card)' }}
          />
        </div>
        <div className="flex gap-1 p-1 rounded-full" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
          {(['all', 'open', 'pending_response', 'responded', 'closed'] as const).map(s => (
            <button key={s} onClick={() => setStatusFilter(s)}
              className="px-3 py-1.5 rounded-full text-xs font-medium transition-all active:scale-[0.97]"
              style={statusFilter === s ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' } : { color: 'var(--text-secondary)' }}>
              {s === 'all' ? 'All' : RFI_STATUS_LABELS[s] ?? s}
            </button>
          ))}
        </div>
      </div>

      {/* Table */}
      <div className="rounded-2xl overflow-x-auto" data-tour="rfis-table" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <table className="w-full min-w-[640px] text-sm">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['RFI #', 'Subject', 'Priority', 'Status', 'Raised', 'Response Due', ''].map(h => (
                <th key={h} className="text-left px-5 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
            {isLoading ? (
              [...Array(5)].map((_, i) => (
                <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                  {[...Array(7)].map((_, j) => (
                    <td key={j} className="px-5 py-4">
                      <div className="h-4 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)', width: '70%' }} />
                    </td>
                  ))}
                </tr>
              ))
            ) : isError ? (
              // A query failure previously fell through to the empty-state
              // branch below (data stayed undefined, isLoading settled to
              // false) — indistinguishable from "no RFIs exist yet". See
              // internal-docs/error-messaging-recovery-ux-audit.md's Batch 5
              // fake-empty-state notes.
              <tr>
                <td colSpan={7} className="px-5 py-12 text-center">
                  <MessageSquare size={28} className="mx-auto mb-2" style={{ color: '#f87171' }} />
                  <p className="text-sm" style={{ color: 'var(--text-primary)' }}>We couldn&rsquo;t load RFIs</p>
                  <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{getErrorMessage(error, 'Please try again.')}</p>
                  <Button onClick={() => refetch()} variant="secondary" size="sm" className="mt-3">
                    Try again
                  </Button>
                </td>
              </tr>
            ) : rfis.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-5 py-12 text-center">
                  <MessageSquare size={28} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
                  <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No RFIs yet</p>
                  {canWrite && (
                  <Button onClick={() => setShowModal(true)} variant="secondary" size="sm" className="mt-3">
                    Raise First RFI
                  </Button>
                  )}
                </td>
              </tr>
            ) : rfis.map((r: any) => {
              const badge = STATUS_COLORS[r.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
              return (
                <tr key={r.id} className="hover:bg-[var(--bg-hover)] transition-colors cursor-pointer" style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="px-5 py-3 font-mono text-[11px] font-semibold" style={{ color: 'var(--gold)' }}>#{r.rfi_number}</td>
                  <td className="px-5 py-3 font-medium max-w-[240px] truncate" style={{ color: 'var(--text-primary)' }}>{r.subject}</td>
                  <td className="px-5 py-3">
                    <span className="text-xs font-medium" style={{ color: r.priority === 'urgent' ? '#ef4444' : r.priority === 'high' ? '#f59e0b' : 'var(--text-muted)' }}>
                      {PRIORITY_LABELS[r.priority] ?? r.priority}
                    </span>
                  </td>
                  <td className="px-5 py-3">
                    <span className="px-2 py-0.5 rounded-full text-xs font-medium"
                          style={{ backgroundColor: badge.bg, color: badge.text }}>
                      {RFI_STATUS_LABELS[r.status] ?? r.status}
                    </span>
                  </td>
                  <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{r.raised_date ? formatDate(r.raised_date) : '—'}</td>
                  <td className="px-5 py-3 text-xs" style={{ color: r.response_due_date ? 'var(--text-secondary)' : 'var(--text-muted)' }}>
                    {r.response_due_date ? formatDate(r.response_due_date) : '—'}
                  </td>
                  <td className="px-5 py-3">
                    {canWrite && r.status !== 'closed' && (
                      <div className="flex gap-1">
                        {r.status !== 'responded' && (
                          <button onClick={e => { e.stopPropagation(); setRespondRfi(r); }}
                            className="text-xs px-2 py-1 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
                            style={{ color: 'var(--gold)' }}>
                            Respond
                          </button>
                        )}
                        <button onClick={e => { e.stopPropagation(); closeRfi(r); }}
                          className="text-xs px-2 py-1 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
                          style={{ color: 'var(--text-muted)' }}>
                          Close
                        </button>
                      </div>
                    )}
                    <PromptActionButton
                      label="Prompt"
                      module="RFIs"
                      recordType="rfi"
                      recordId={r.id}
                      projectId={id}
                    />
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {canWrite && showModal && <NewRfiModal projectId={id!} onClose={() => setShowModal(false)} />}
      {canWrite && respondRfi && (
        <RfiResponseModal rfi={respondRfi} projectId={id!} onClose={() => setRespondRfi(null)} />
      )}
    </div>
  );
}

export default function GatedProjectRfisPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id as string;
  return (
    <FeatureAvailabilityGate featureKey="project.rfis" title="RFIs" backHref={`/app/projects/${id}/overview`} backLabel="Back to Project Overview">
      <ProjectRfisPage />
    </FeatureAvailabilityGate>
  );
}
