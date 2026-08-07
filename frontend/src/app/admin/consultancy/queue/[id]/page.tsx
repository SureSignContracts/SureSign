'use client';

import { useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import Link from 'next/link';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, HeartHandshake, Search, Video, X } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '@/lib/api';
import { Badge } from '@/components/ui/Badge';
import Button from '@/components/ui/Button';
import { formatDateTime } from '@/lib/dateTime';
import { getErrorMessage } from '@/lib/getErrorMessage';

interface Activity {
  action: string;
  description: string;
  actor_name: string | null;
  meta: Record<string, unknown> | null;
  created_at: string;
}

interface OperatorConsultation {
  id: number;
  reference: string;
  status: string;
  starts_at: string;
  ends_at: string;
  booking_timezone: string;
  created_at: string;
  updated_at: string;
  attendee_name: string;
  attendee_email: string;
  attendee_phone: string | null;
  attendee_company: string | null;
  attendee_job_title: string | null;
  organization_id: number;
  organization: { name: string } | null;
  appointment_type: { name: string } | null;
  assigned_consultant: { id: number; name: string } | null;
  consultation_enquiry: {
    title: string;
    description: string;
    project_stage: string | null;
    contract_form: string | null;
    preferred_outcome: string | null;
    submitted_by: string;
    consultancy_service: { code: string; display_name: string } | null;
    engagement_status: string;
    internal_notes: string | null;
    customer_summary_draft: string | null;
    customer_summary_published: string | null;
    customer_summary_published_at: string | null;
    customer_summary_needs_republish: boolean;
  } | null;
  project: {
    id: number;
    name: string;
    code: string | null;
    status: string;
    client: { id: number; name: string } | null;
    organization: { id: number; name: string } | null;
  } | null;
  activity: Activity[];
  // Only present on show() (the single-record detail this page renders) —
  // index() (the queue list) has no use for Meet status, so it's omitted
  // there. See ConsultancyOperationsController::show()'s own docblock.
  meeting: {
    status: 'available' | 'pending' | 'temporarily_unavailable' | 'unavailable';
    join_url: string | null;
  };
  permissions: {
    can_edit_notes: boolean;
    can_publish_summary: boolean;
    can_change_status: boolean;
    can_link_project: boolean;
    can_reassign: boolean;
    can_reopen: boolean;
  };
}

interface ProjectSearchResult {
  id: number;
  name: string;
  code: string | null;
  organization_id: number;
}

const ENGAGEMENT_TONE: Record<string, 'neutral' | 'success' | 'warning' | 'info' | 'danger' | 'accent'> = {
  awaiting_consultant: 'warning', awaiting_customer: 'info', completed: 'success', cancelled: 'neutral',
};

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="rounded-2xl p-5 space-y-3" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{title}</h2>
      {children}
    </div>
  );
}

function Field({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="text-sm" style={{ color: 'var(--text-primary)' }}>{value ?? '—'}</p>
    </div>
  );
}

function ErrorBanner({ message }: { message: string | null }) {
  if (!message) return null;
  return (
    <p role="alert" className="text-xs rounded-lg px-3 py-2" style={{ backgroundColor: 'rgba(239,68,68,0.1)', color: '#f87171' }}>
      {message}
    </p>
  );
}

/**
 * Local editable state is seeded from `initialValue` at mount time only —
 * the parent remounts this component (via a `key` tied to the record's
 * `updated_at`) whenever fresh server data should take over, rather than
 * an effect syncing state on every render (avoids clobbering an
 * in-progress edit on an unrelated background refetch).
 */
function NotesEditor({
  initialValue, onSave, saving, error,
}: {
  initialValue: string; onSave: (value: string) => void; saving: boolean; error: string | null;
}) {
  const [value, setValue] = useState(initialValue);
  return (
    <>
      <ErrorBanner message={error} />
      <label htmlFor="internal-notes" className="sr-only">Internal notes</label>
      <textarea
        id="internal-notes"
        value={value}
        onChange={e => setValue(e.target.value)}
        rows={4}
        className="w-full px-3.5 py-2.5 rounded-lg text-sm outline-none resize-none focus:ring-2 focus:ring-[var(--gold)]/30"
        style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
      />
      <div className="flex justify-end">
        <Button size="sm" disabled={saving} onClick={() => onSave(value)}>
          {saving ? 'Saving…' : 'Save Notes'}
        </Button>
      </div>
    </>
  );
}

function SummaryDraftEditor({
  initialValue, alreadyPublished, onSaveDraft, onPublish, savingDraft, publishing, error,
}: {
  initialValue: string; alreadyPublished: boolean;
  onSaveDraft: (value: string) => void; onPublish: () => void;
  savingDraft: boolean; publishing: boolean; error: string | null;
}) {
  const [value, setValue] = useState(initialValue);
  return (
    <>
      <ErrorBanner message={error} />
      <label htmlFor="summary-draft" className="sr-only">Customer summary draft</label>
      <textarea
        id="summary-draft"
        value={value}
        onChange={e => setValue(e.target.value)}
        rows={5}
        className="w-full px-3.5 py-2.5 rounded-lg text-sm outline-none resize-none focus:ring-2 focus:ring-[var(--gold)]/30"
        style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
      />
      <div className="flex justify-end gap-2 pt-1">
        <Button size="sm" variant="secondary" disabled={savingDraft} onClick={() => onSaveDraft(value)}>
          {savingDraft ? 'Saving…' : 'Save Draft'}
        </Button>
        <Button size="sm" disabled={publishing || !value.trim()} onClick={onPublish}>
          {publishing ? 'Publishing…' : alreadyPublished ? 'Republish' : 'Publish'}
        </Button>
      </div>
    </>
  );
}

/**
 * The Linked Project card — link/change/unlink a Project against this
 * consultation. Same-organisation enforcement happens server-side
 * (linkProject()); this component only filters the search results to the
 * consultation's own organisation client-side, as a UX convenience so an
 * operator isn't shown projects they'd immediately be rejected for.
 */
function ProjectLinker({
  organizationId, currentProject, canLink, onLink, onUnlink, linking, unlinking, error,
}: {
  organizationId: number | null;
  currentProject: OperatorConsultation['project'];
  canLink: boolean;
  onLink: (projectId: number) => void;
  onUnlink: () => void;
  linking: boolean;
  unlinking: boolean;
  error: string | null;
}) {
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [picking, setPicking] = useState(false);

  useEffect(() => {
    const t = setTimeout(() => setDebouncedSearch(search), 350);
    return () => clearTimeout(t);
  }, [search]);

  const { data: results, isFetching, isError: searchError } = useQuery({
    queryKey: ['admin-consultancy-project-search', organizationId, debouncedSearch],
    queryFn: () => api.get('/projects', {
      params: { organization_id: organizationId, search: debouncedSearch, per_page: 10 },
    }).then(r => r.data.data as ProjectSearchResult[]),
    enabled: picking && debouncedSearch.trim().length > 1 && !!organizationId,
  });

  if (!canLink) {
    return currentProject ? (
      <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>
        {currentProject.name} {currentProject.code ? `(${currentProject.code})` : ''}
      </p>
    ) : (
      <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No project linked.</p>
    );
  }

  return (
    <div className="space-y-2">
      <ErrorBanner message={error} />
      {currentProject && !picking && (
        <div className="flex items-center justify-between gap-3 flex-wrap">
          <div>
            <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{currentProject.name}</p>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
              {[currentProject.code, currentProject.client?.name].filter(Boolean).join(' · ') || 'No further details.'}
            </p>
          </div>
          <div className="flex gap-2">
            <Button size="sm" variant="secondary" onClick={() => setPicking(true)}>Change</Button>
            <Button
              size="sm"
              variant="secondary"
              disabled={unlinking}
              onClick={() => { if (confirm('Remove this project link?')) onUnlink(); }}
            >
              {unlinking ? 'Removing…' : 'Unlink'}
            </Button>
          </div>
        </div>
      )}

      {!currentProject && !picking && (
        <div className="flex items-center justify-between gap-3">
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No project linked.</p>
          <Button size="sm" variant="secondary" onClick={() => setPicking(true)}>Link Project</Button>
        </div>
      )}

      {picking && (
        <div className="space-y-2">
          <div className="flex items-center gap-2">
            <div className="relative flex-1">
              <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
              <label htmlFor="project-search" className="sr-only">Search projects</label>
              <input
                id="project-search"
                autoFocus
                value={search}
                onChange={e => setSearch(e.target.value)}
                placeholder="Search by project name, code, or client…"
                className="w-full pl-9 pr-3 py-2 rounded-lg text-sm outline-none focus:ring-2 focus:ring-[var(--gold)]/30"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
              />
            </div>
            <Button size="sm" variant="secondary" onClick={() => { setPicking(false); setSearch(''); }}>
              <X size={14} />
            </Button>
          </div>

          {debouncedSearch.trim().length > 1 && (
            <div className="rounded-lg divide-y max-h-56 overflow-y-auto" style={{ border: '1px solid var(--border)' }}>
              {isFetching && <p className="text-xs p-2" style={{ color: 'var(--text-muted)' }}>Searching…</p>}
              {!isFetching && searchError && (
                <p className="text-xs p-2" style={{ color: '#f87171' }}>Search failed — please try again.</p>
              )}
              {!isFetching && !searchError && results?.length === 0 && (
                <p className="text-xs p-2" style={{ color: 'var(--text-muted)' }}>No matching projects in this organisation.</p>
              )}
              {!isFetching && !searchError && results?.map(project => (
                <button
                  key={project.id}
                  type="button"
                  disabled={linking}
                  onClick={() => { onLink(project.id); setPicking(false); setSearch(''); }}
                  className="w-full text-left px-3 py-2 text-sm hover:bg-[var(--bg-elevated)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px]"
                  style={{ color: 'var(--text-primary)' }}
                >
                  {project.name} {project.code ? <span style={{ color: 'var(--text-muted)' }}>({project.code})</span> : null}
                </button>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

export default function ConsultancyOperatorDetailPage() {
  const params = useParams();
  const id = params.id as string;
  const qc = useQueryClient();

  const { data: consultation, isLoading, isError, refetch } = useQuery({
    queryKey: ['admin-consultancy-consultation', id],
    queryFn: () => api.get(`/admin/consultancy/consultations/${id}`).then(r => r.data as OperatorConsultation),
  });

  const [notesError, setNotesError] = useState<string | null>(null);
  const [summaryError, setSummaryError] = useState<string | null>(null);
  const [statusError, setStatusError] = useState<string | null>(null);

  function invalidate() {
    qc.invalidateQueries({ queryKey: ['admin-consultancy-consultation', id] });
    qc.invalidateQueries({ queryKey: ['admin-consultancy-queue'] });
  }

  const notesMutation = useMutation({
    mutationFn: (internal_notes: string) => api.put(`/admin/consultancy/consultations/${id}/notes`, { internal_notes }).then(r => r.data),
    onSuccess: () => { toast.success('Notes saved.'); setNotesError(null); invalidate(); },
    onError: (err: any) => setNotesError(getErrorMessage(err, 'Failed to save notes.')),
  });

  const summaryMutation = useMutation({
    mutationFn: (customer_summary_draft: string) => api.put(`/admin/consultancy/consultations/${id}/summary`, { customer_summary_draft }).then(r => r.data),
    onSuccess: () => { toast.success('Draft saved.'); setSummaryError(null); invalidate(); },
    onError: (err: any) => setSummaryError(getErrorMessage(err, 'Failed to save draft.')),
  });

  const publishMutation = useMutation({
    mutationFn: () => api.post(`/admin/consultancy/consultations/${id}/summary/publish`).then(r => r.data),
    onSuccess: () => { toast.success('Summary published — the customer can now see it.'); setSummaryError(null); invalidate(); },
    onError: (err: any) => setSummaryError(getErrorMessage(err, 'Failed to publish.')),
  });

  const statusMutation = useMutation({
    mutationFn: (action: 'awaiting-customer' | 'awaiting-consultant' | 'complete') =>
      api.post(`/admin/consultancy/consultations/${id}/status/${action}`).then(r => r.data),
    onSuccess: () => { toast.success('Status updated.'); setStatusError(null); invalidate(); },
    onError: (err: any) => setStatusError(getErrorMessage(err, 'Failed to update status.')),
  });

  const reopenMutation = useMutation({
    mutationFn: () => api.post(`/admin/consultancy/consultations/${id}/reopen`).then(r => r.data),
    onSuccess: () => { toast.success('Engagement reopened.'); setStatusError(null); invalidate(); },
    onError: (err: any) => setStatusError(getErrorMessage(err, 'Failed to reopen.')),
  });

  const [projectError, setProjectError] = useState<string | null>(null);

  const linkProjectMutation = useMutation({
    mutationFn: (project_id: number) => api.put(`/admin/consultancy/consultations/${id}/project`, { project_id }).then(r => r.data),
    onSuccess: () => { toast.success('Project linked.'); setProjectError(null); invalidate(); },
    onError: (err: any) => setProjectError(getErrorMessage(err, 'Failed to link project.')),
  });

  const unlinkProjectMutation = useMutation({
    mutationFn: () => api.delete(`/admin/consultancy/consultations/${id}/project`).then(r => r.data),
    onSuccess: () => { toast.success('Project unlinked.'); setProjectError(null); invalidate(); },
    onError: (err: any) => setProjectError(getErrorMessage(err, 'Failed to unlink project.')),
  });

  if (isLoading) {
    return (
      <div className="p-6 max-w-4xl mx-auto space-y-4" aria-busy="true" aria-live="polite">
        <span className="sr-only">Loading consultation…</span>
        <div className="h-6 w-40 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        <div className="h-48 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
      </div>
    );
  }

  if (isError) {
    return (
      <div className="p-6 max-w-4xl mx-auto space-y-3">
        <p style={{ color: '#f87171' }}>We couldn&apos;t load this consultation.</p>
        <Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>
      </div>
    );
  }

  if (!consultation) {
    return (
      <div className="p-6 max-w-4xl mx-auto">
        <p style={{ color: 'var(--text-muted)' }}>Consultation not found.</p>
      </div>
    );
  }

  const enquiry = consultation.consultation_enquiry;
  const { permissions } = consultation;
  const isTerminal = enquiry?.engagement_status === 'completed' || enquiry?.engagement_status === 'cancelled';

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-5">
      <Link href="/admin/consultancy/queue" className="inline-flex items-center gap-1 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 rounded" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={14} /> Back to Consultancy Queue
      </Link>

      <div className="flex items-start justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-xl font-bold flex items-center gap-2" style={{ color: 'var(--text-primary)' }}>
            <HeartHandshake size={20} /> {enquiry?.consultancy_service?.display_name ?? 'Consultation'}
          </h1>
          <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>Reference {consultation.reference}</p>
        </div>
        <div className="flex items-center gap-2">
          {enquiry && <Badge tone={ENGAGEMENT_TONE[enquiry.engagement_status]}>{enquiry.engagement_status.replace(/_/g, ' ')}</Badge>}
          <Badge status={consultation.status} />
        </div>
      </div>

      {!permissions.can_edit_notes && (
        <p className="text-xs rounded-lg px-3 py-2" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
          Read-only — this consultation isn&apos;t assigned to you. You can view it here for continuity, but only the assigned consultant or a Super Admin can make changes.
        </p>
      )}
      {permissions.can_edit_notes && isTerminal && enquiry?.engagement_status === 'completed' && !permissions.can_reopen && (
        <p className="text-xs rounded-lg px-3 py-2" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
          This engagement is completed. Ask a Super Admin to reopen it before making further changes.
        </p>
      )}

      {enquiry && (
        <Section title="Actions">
          <ErrorBanner message={statusError} />
          <div className="flex flex-wrap gap-2">
            {permissions.can_change_status && enquiry.engagement_status === 'awaiting_consultant' && (
              <Button size="sm" variant="secondary" disabled={statusMutation.isPending} onClick={() => statusMutation.mutate('awaiting-customer')}>
                Mark Awaiting Customer
              </Button>
            )}
            {permissions.can_change_status && enquiry.engagement_status === 'awaiting_customer' && (
              <Button size="sm" variant="secondary" disabled={statusMutation.isPending} onClick={() => statusMutation.mutate('awaiting-consultant')}>
                Mark Awaiting Consultant
              </Button>
            )}
            {permissions.can_change_status && !isTerminal && (
              <Button
                size="sm"
                disabled={statusMutation.isPending}
                onClick={() => { if (confirm('Mark this engagement as completed?')) statusMutation.mutate('complete'); }}
              >
                Mark Completed
              </Button>
            )}
            {permissions.can_reopen && enquiry.engagement_status === 'completed' && (
              <Button
                size="sm"
                variant="secondary"
                disabled={reopenMutation.isPending}
                onClick={() => { if (confirm('Reopen this completed engagement?')) reopenMutation.mutate(); }}
              >
                {reopenMutation.isPending ? 'Reopening…' : 'Reopen'}
              </Button>
            )}
            {!permissions.can_change_status && !permissions.can_reopen && (
              <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No actions available.</p>
            )}
          </div>
        </Section>
      )}

      {/* Consolidated (was four separate stacked cards — Overview/Organisation/
          Service/Appointment — each holding only one to three small fields.
          Merging them into one grid removes three redundant card
          borders/headers/paddings, the main contributor to this page
          needing far more scrolling than its actual amount of information
          warranted. */}
      <Section title="Details">
        <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
          <Field label="Consultant" value={consultation.assigned_consultant?.name ?? 'Unassigned'} />
          <Field label="Organisation" value={consultation.organization?.name} />
          <Field label="Consultancy service" value={enquiry?.consultancy_service?.display_name} />
          <Field label="Type" value={consultation.appointment_type?.name} />
          <Field label="Date" value={formatDateTime(consultation.starts_at, { timeZone: consultation.booking_timezone })} />
          <Field label="Ends" value={formatDateTime(consultation.ends_at, { timeZone: consultation.booking_timezone })} />
          <Field label="Timezone" value={consultation.booking_timezone} />
          <Field label="Created" value={formatDateTime(consultation.created_at)} />
          <Field label="Last updated" value={formatDateTime(consultation.updated_at)} />
        </div>
      </Section>

      {consultation.meeting.status !== 'unavailable' && (
        <Section title="Meeting">
          <div className="flex items-center gap-2">
            <Video size={16} style={{ color: 'var(--text-muted)' }} />
            {consultation.meeting.status === 'available' && consultation.meeting.join_url ? (
              <a
                href={consultation.meeting.join_url}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-2 text-sm font-medium px-4 py-2 rounded-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                style={{ backgroundColor: 'var(--accent, #2563eb)', color: '#fff' }}
              >
                <Video size={14} /> Join Google Meet
              </a>
            ) : (
              <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
                {consultation.meeting.status === 'pending' || consultation.meeting.status === 'temporarily_unavailable'
                  ? 'Preparing meeting link…'
                  : 'Meeting link unavailable.'}
              </p>
            )}
          </div>
        </Section>
      )}

      <Section title="Customer">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
          <Field label="Name" value={consultation.attendee_name} />
          <Field label="Email" value={consultation.attendee_email} />
          <Field label="Phone" value={consultation.attendee_phone} />
          <Field label="Company / job title" value={[consultation.attendee_company, consultation.attendee_job_title].filter(Boolean).join(' · ') || null} />
        </div>
      </Section>

      <Section title="Linked Project">
        <ProjectLinker
          organizationId={consultation.organization_id}
          currentProject={consultation.project}
          canLink={permissions.can_link_project}
          onLink={projectId => linkProjectMutation.mutate(projectId)}
          onUnlink={() => unlinkProjectMutation.mutate()}
          linking={linkProjectMutation.isPending}
          unlinking={unlinkProjectMutation.isPending}
          error={projectError}
        />
      </Section>

      {enquiry && (
        <Section title="Enquiry">
          <div className="space-y-2 text-sm">
            <p className="font-medium" style={{ color: 'var(--text-primary)' }}>{enquiry.title}</p>
            <p style={{ color: 'var(--text-secondary)' }}>{enquiry.description}</p>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-1">
              <Field label="Project stage" value={enquiry.project_stage} />
              <Field label="Contract form" value={enquiry.contract_form} />
              <Field label="Submitted via" value={enquiry.submitted_by} />
            </div>
            {enquiry.preferred_outcome && <Field label="Preferred outcome" value={enquiry.preferred_outcome} />}
          </div>
        </Section>
      )}

      {enquiry && (
        <Section title="Internal Notes">
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Consultant-only — never visible to the customer.</p>
          {permissions.can_edit_notes ? (
            <NotesEditor
              key={consultation.updated_at}
              initialValue={enquiry.internal_notes ?? ''}
              onSave={value => notesMutation.mutate(value)}
              saving={notesMutation.isPending}
              error={notesError}
            />
          ) : (
            <p className="text-sm whitespace-pre-wrap" style={{ color: 'var(--text-secondary)' }}>{enquiry.internal_notes || 'No notes yet.'}</p>
          )}
        </Section>
      )}

      {enquiry && (
        <Section title="Customer Summary">
          <div className="space-y-3">
            <div>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Published (visible to the customer)</p>
              <p className="text-sm whitespace-pre-wrap" style={{ color: 'var(--text-secondary)' }}>{enquiry.customer_summary_published || 'Not published yet.'}</p>
              {enquiry.customer_summary_published_at && (
                <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>Published {formatDateTime(enquiry.customer_summary_published_at)}</p>
              )}
            </div>

            <div>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                Draft {enquiry.customer_summary_needs_republish && <span style={{ color: '#facc15' }}>· unpublished changes</span>}
              </p>
              {permissions.can_publish_summary ? (
                <SummaryDraftEditor
                  key={consultation.updated_at}
                  initialValue={enquiry.customer_summary_draft ?? ''}
                  alreadyPublished={!!enquiry.customer_summary_published_at}
                  onSaveDraft={value => summaryMutation.mutate(value)}
                  onPublish={() => publishMutation.mutate()}
                  savingDraft={summaryMutation.isPending}
                  publishing={publishMutation.isPending}
                  error={summaryError}
                />
              ) : (
                <p className="text-sm whitespace-pre-wrap" style={{ color: 'var(--text-secondary)' }}>{enquiry.customer_summary_draft || 'No draft yet.'}</p>
              )}
            </div>
          </div>
        </Section>
      )}

      <Section title="Activity">
        {consultation.activity.length === 0 ? (
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No activity recorded yet.</p>
        ) : (
          <ul className="space-y-2">
            {consultation.activity.map((event, i) => (
              <li key={i} className="text-sm flex items-start justify-between gap-3 py-1.5" style={{ borderBottom: i < consultation.activity.length - 1 ? '1px solid var(--border)' : undefined }}>
                <div>
                  <p style={{ color: 'var(--text-primary)' }}>{event.description}</p>
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{event.actor_name ?? 'System'}</p>
                </div>
                <p className="text-xs whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{formatDateTime(event.created_at)}</p>
              </li>
            ))}
          </ul>
        )}
      </Section>
    </div>
  );
}
