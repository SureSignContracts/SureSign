'use client';

import { useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import {
  Scale, ArrowLeft, ChevronRight, CheckCircle2, Circle, Clock,
  AlertCircle, FileText, Plus, X, Upload, Calendar, ChevronDown,
  History, Link2, ExternalLink, Archive, AlertTriangle, Activity, Tag,
  User, Zap,
} from 'lucide-react';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import toast from 'react-hot-toast';
import PromptActionButton from '@/components/prompts/PromptActionButton';

// ─── Constants ───────────────────────────────────────────────────────────────

const STEP_DEFINITIONS = [
  { key: 'notice_of_dispute',       title: 'Notice of Dispute',       buttonLabel: 'Generate Notice of Dispute',         docType: 'notice_of_dispute' },
  { key: 'notice_of_adjudication',  title: 'Notice of Adjudication',  buttonLabel: 'Generate Notice of Adjudication',    docType: 'notice_of_adjudication' },
  { key: 'adjudicator_appointment', title: 'Adjudicator Appointment', buttonLabel: 'Prepare Appointment Application',     docType: 'adjudicator_application' },
  { key: 'referral_submission',     title: 'Referral Submission',      buttonLabel: 'Prepare Referral Pack',               docType: 'referral_submission' },
  { key: 'response_analysis',       title: 'Response Analysis',        buttonLabel: 'Upload / Analyse Response',           docType: 'response' },
  { key: 'further_submissions',     title: 'Further Submissions',      buttonLabel: 'Draft Further Submission',            docType: 'further_submission' },
  { key: 'decision_analysis',       title: 'Decision Analysis',        buttonLabel: 'Upload Decision',                     docType: 'decision' },
  { key: 'enforcement',             title: 'Enforcement',              buttonLabel: 'Generate Enforcement Documents',      docType: 'enforcement_letter' },
];

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  draft:                   { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  notice_of_dispute:       { bg: 'rgba(249,115,22,0.12)', text: '#fb923c' },
  notice_of_adjudication:  { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  adjudicator_appointment: { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  referral_submission:     { bg: 'rgba(168,85,247,0.12)', text: '#c084fc' },
  response_analysis:       { bg: 'rgba(20,184,166,0.12)', text: '#2dd4bf' },
  further_submissions:     { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  decision_analysis:       { bg: 'rgba(249,115,22,0.12)', text: '#fb923c' },
  enforcement:             { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
  closed:                  { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
};

const STEP_STATUS_COLORS: Record<string, { bg: string; text: string; icon: 'check' | 'circle' | 'progress' }> = {
  completed:   { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80', icon: 'check' },
  in_progress: { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa', icon: 'progress' },
  pending:     { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490', icon: 'circle' },
  skipped:     { bg: 'rgba(90,86,82,0.2)',    text: '#6b6866', icon: 'circle' },
};

const DOC_CATEGORY_LABELS: Record<string, string> = {
  notices:       'Notices',
  referral:      'Referral',
  responses:     'Responses',
  evidence:      'Evidence',
  decisions:     'Decisions',
  enforcement:   'Enforcement',
  correspondence: 'Correspondence',
};

// Status labels mapping step-based statuses to operational display names
const STATUS_DISPLAY: Record<string, string> = {
  draft:                   'Draft',
  active:                  'Active',
  awaiting_response:       'Awaiting Response',
  decision_pending:        'Decision Pending',
  notice_of_dispute:       'Notice of Dispute',
  notice_of_adjudication:  'Notice of Adjudication',
  adjudicator_appointment: 'Adjudicator Appointment',
  referral_submission:     'Referral Submission',
  response_analysis:       'Response Analysis',
  further_submissions:     'Further Submissions',
  decision_analysis:       'Decision Analysis',
  enforcement:             'Enforcement',
  closed:                  'Closed',
  archived:                'Archived',
};

// Step action definitions — upload actions open Add Document modal; AI/mark actions are placeholders
type StepAction = { label: string; docType?: string; ai?: boolean; placeholder?: boolean };
const STEP_ACTIONS: Record<string, StepAction[]> = {
  notice_of_dispute:       [{ label: 'Upload Notice', docType: 'notice_of_dispute' }, { label: 'Mark as Sent', placeholder: true }],
  notice_of_adjudication:  [{ label: 'Upload Signed Notice', docType: 'notice_of_adjudication' }, { label: 'Mark as Issued', placeholder: true }],
  adjudicator_appointment: [{ label: 'Upload Appointment Form', docType: 'adjudicator_application' }, { label: 'Add Adjudicator Details', placeholder: true }, { label: 'Mark Appointed', placeholder: true }],
  referral_submission:     [{ label: 'Upload Referral', docType: 'referral_submission' }, { label: 'Generate Bundle', ai: true }, { label: 'AI Summarise', ai: true }],
  response_analysis:       [{ label: 'Upload Response', docType: 'response' }, { label: 'AI Analyse Response', ai: true }],
  further_submissions:     [{ label: 'Upload Reply', docType: 'further_submission' }, { label: 'AI Draft Counterarguments', ai: true }],
  decision_analysis:       [{ label: 'Upload Decision', docType: 'decision' }, { label: 'AI Summarise Decision', ai: true }],
  enforcement:             [{ label: 'Upload Enforcement Letter', docType: 'enforcement_letter' }, { label: 'Generate Payment Demand', ai: true }],
};

// ─── Helpers ─────────────────────────────────────────────────────────────────

function computeDaysRemaining(dueDate: string): { days: number; label: string; status: 'overdue' | 'today' | 'due_soon' | 'upcoming' | 'completed' } {
  const due = new Date(dueDate);
  const now = new Date();
  now.setHours(0, 0, 0, 0);
  due.setHours(0, 0, 0, 0);
  const diff = Math.round((due.getTime() - now.getTime()) / (1000 * 60 * 60 * 24));
  if (diff < 0)  return { days: Math.abs(diff), label: `Overdue by ${Math.abs(diff)} day${Math.abs(diff) !== 1 ? 's' : ''}`,   status: 'overdue' };
  if (diff === 0) return { days: 0,              label: 'Due today',                                                               status: 'today' };
  if (diff <= 3)  return { days: diff,            label: `${diff} day${diff !== 1 ? 's' : ''} remaining`,                         status: 'due_soon' };
  return              { days: diff,              label: `${diff} days remaining`,                                                  status: 'upcoming' };
}

const DAYS_STATUS_COLORS: Record<string, { text: string; bg: string }> = {
  overdue:   { text: '#f87171', bg: 'rgba(239,68,68,0.1)' },
  today:     { text: '#fb923c', bg: 'rgba(249,115,22,0.1)' },
  due_soon:  { text: '#facc15', bg: 'rgba(234,179,8,0.1)' },
  upcoming:  { text: '#60a5fa', bg: 'rgba(59,130,246,0.1)' },
  completed: { text: '#4ade80', bg: 'rgba(34,197,94,0.1)' },
};

const DOC_TYPE_LABELS: Record<string, string> = {
  notice_of_dispute:       'Notice of Dispute',
  notice_of_adjudication:  'Notice of Adjudication',
  adjudicator_application: 'Adjudicator Application',
  referral_submission:     'Referral Submission',
  response:                'Response',
  further_submission:      'Further Submission',
  decision:                'Decision',
  enforcement_letter:      'Enforcement Letter',
  evidence:                'Evidence',
  supporting_document:     'Supporting Document',
  other:                   'Other',
};

const DOC_STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  draft:          { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  pending_review: { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  approved:       { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  issued:         { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  archived:       { bg: 'rgba(90,86,82,0.2)',    text: '#6b6866' },
};

const DEADLINE_STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  upcoming:  { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  due_soon:  { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  overdue:   { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
  completed: { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
};

// ─── Helpers ─────────────────────────────────────────────────────────────────

const inputStyle = {
  backgroundColor: 'var(--bg-elevated)',
  border: '1px solid var(--border)',
  color: 'var(--text-primary)',
};

// ─── Add Document Modal ───────────────────────────────────────────────────────

function AddDocumentModal({
  caseId, projectId, defaultDocType, sourceStep, onClose,
}: {
  caseId: string; projectId: string; defaultDocType?: string; sourceStep?: string; onClose: () => void;
}) {
  const qc = useQueryClient();
  const [mode, setMode] = useState<'upload' | 'draft'>('upload');
  const [file, setFile] = useState<File | null>(null);
  const [form, setForm] = useState({
    title:         '',
    document_type: defaultDocType ?? 'other',
    category:      '',
    source_step:   sourceStep ?? '',
    status:        'draft',
    file_name:     '',
    ai_generated:  false,
  });

  const mutation = useMutation({
    mutationFn: (data: typeof form) => {
      if (mode === 'upload' && file) {
        const fd = new FormData();
        fd.append('file', file);
        fd.append('title', data.title || file.name);
        fd.append('document_type', data.document_type);
        if (data.category) fd.append('category', data.category);
        if (data.source_step) fd.append('source_step', data.source_step);
        fd.append('status', data.status);
        return api.post(
          `/projects/${projectId}/adjudication-cases/${caseId}/documents`,
          fd,
          { headers: { 'Content-Type': 'multipart/form-data' } }
        ).then(r => r.data);
      }
      return api.post(`/projects/${projectId}/adjudication-cases/${caseId}/documents`, {
        ...data,
        ai_generated: data.ai_generated ? 1 : 0,
        file_name: data.file_name || undefined,
      }).then(r => r.data);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['adjudication-documents', caseId] });
      qc.invalidateQueries({ queryKey: ['adjudication-case', caseId] });
      onClose();
    },
  });

  const set = (k: keyof typeof form) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) =>
      setForm(f => ({ ...f, [k]: e.target.value }));

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-md rounded-2xl overflow-hidden ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        <div className="flex items-center justify-between px-6 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Add Document</h2>
          <button onClick={onClose} className="p-1 rounded-lg hover:bg-[var(--bg-hover)]">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        {/* Mode toggle */}
        <div className="flex px-6 pt-4 gap-2">
          {(['upload', 'draft'] as const).map(m => (
            <button key={m} type="button" onClick={() => setMode(m)}
              className="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
              style={{
                backgroundColor: mode === m ? 'var(--gold)' : 'var(--bg-elevated)',
                color: mode === m ? 'var(--accent-fg)' : 'var(--text-secondary)',
              }}>
              {m === 'upload' ? 'Upload File' : 'Create Draft Record'}
            </button>
          ))}
        </div>

        <form onSubmit={e => { e.preventDefault(); mutation.mutate(form); }} className="p-6 space-y-4">
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Document Title *</label>
            <input value={form.title} onChange={set('title')} required={mode === 'draft'} placeholder="e.g. Notice of Dispute – Draft"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>

          {mode === 'upload' ? (
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>File *</label>
              <input type="file" required onChange={e => { const f = e.target.files?.[0] ?? null; setFile(f); if (f && !form.title) setForm(p => ({ ...p, title: f.name.replace(/\.[^.]+$/, '') })); }}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          ) : (
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>File Name (reference)</label>
              <input value={form.file_name} onChange={set('file_name')} placeholder="e.g. notice_of_dispute_v1.pdf"
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          )}

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Document Type *</label>
              <select value={form.document_type} onChange={set('document_type')} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                {Object.entries(DOC_TYPE_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Category</label>
              <select value={form.category} onChange={set('category')} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                <option value="">No category</option>
                {Object.entries(DOC_CATEGORY_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Status</label>
              <select value={form.status} onChange={set('status')} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                <option value="draft">Draft</option>
                <option value="pending_review">Pending Review</option>
                <option value="approved">Approved</option>
                <option value="issued">Issued</option>
                <option value="archived">Archived</option>
              </select>
            </div>
          </div>
          {mode === 'draft' && (
            <label className="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" checked={form.ai_generated} onChange={e => setForm(f => ({ ...f, ai_generated: e.target.checked }))} />
              <span className="text-xs" style={{ color: 'var(--text-muted)' }}>AI-generated draft</span>
            </label>
          )}
          {mutation.isError && <p className="text-xs text-red-400">Failed to save document.</p>}
          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={mutation.isPending}
              className="px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60 active:scale-[0.98]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              {mutation.isPending ? 'Saving…' : mode === 'upload' ? 'Upload File' : 'Add Document'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ─── Add Deadline Modal ────────────────────────────────────────────────────────

function AddDeadlineModal({ caseId, projectId, caseCreatedAt, onClose }: { caseId: string; projectId: string; caseCreatedAt?: string; onClose: () => void }) {
  const qc = useQueryClient();
  const [form, setForm] = useState({ title: '', deadline_type: 'custom', due_date: '', description: '' });
  const [dateError, setDateError] = useState('');

  const mutation = useMutation({
    mutationFn: (data: typeof form) =>
      api.post(`/projects/${projectId}/adjudication-cases/${caseId}/deadlines`, data).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['adjudication-deadlines', caseId] });
      onClose();
    },
    onError: (err: any) => toast.error(err?.response?.data?.message ?? 'Failed to save deadline.'),
  });

  const set = (k: keyof typeof form) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) =>
      setForm(f => ({ ...f, [k]: e.target.value }));

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setDateError('');
    if (caseCreatedAt && form.due_date) {
      const caseDate = new Date(caseCreatedAt);
      const dueDate  = new Date(form.due_date);
      if (dueDate < caseDate) {
        setDateError('Due date cannot be before the case creation date.');
        return;
      }
    }
    mutation.mutate(form);
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-md rounded-2xl overflow-hidden ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        <div className="flex items-center justify-between px-6 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Add Deadline</h2>
          <button onClick={onClose} className="p-1 rounded-lg hover:bg-[var(--bg-hover)]">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>
        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Deadline Title *</label>
            <input value={form.title} onChange={set('title')} required placeholder="e.g. Referral submission due"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Type *</label>
              <select value={form.deadline_type} onChange={set('deadline_type')} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                <option value="notice_deadline">Notice Deadline</option>
                <option value="referral_deadline">Referral Deadline</option>
                <option value="response_deadline">Response Deadline</option>
                <option value="decision_deadline">Decision Deadline</option>
                <option value="enforcement_deadline">Enforcement Deadline</option>
                <option value="custom">Custom</option>
              </select>
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Due Date *</label>
              <input type="date" value={form.due_date} onChange={set('due_date')} required
                min={caseCreatedAt ? caseCreatedAt.split('T')[0] : undefined}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          </div>
          {dateError && <p className="text-xs text-red-400">{dateError}</p>}
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Description</label>
            <textarea value={form.description} onChange={set('description')} rows={2} placeholder="Optional notes…"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          {mutation.isError && <p className="text-xs text-red-400">Failed to save deadline.</p>}
          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={mutation.isPending}
              className="px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60 active:scale-[0.98]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              {mutation.isPending ? 'Saving…' : 'Add Deadline'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ─── Advance Step Modal ────────────────────────────────────────────────────────

function AdvanceStepModal({ adjudicationCase, projectId, onClose }: { adjudicationCase: any; projectId: string; onClose: () => void }) {
  const qc = useQueryClient();
  const [notes, setNotes] = useState('');
  const caseId = String(adjudicationCase.id);

  const stepKeys = STEP_DEFINITIONS.map(s => s.key);
  const currentIndex = stepKeys.indexOf(adjudicationCase.current_step);
  const nextStep = currentIndex < stepKeys.length - 1 ? STEP_DEFINITIONS[currentIndex + 1] : null;

  const mutation = useMutation({
    mutationFn: () =>
      api.post(`/projects/${projectId}/adjudication-cases/${caseId}/advance-step`, { notes }).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['adjudication-case', caseId] });
      qc.invalidateQueries({ queryKey: ['project-activities', projectId] });
      qc.invalidateQueries({ queryKey: ['project-adjudication-cases', projectId] });
      onClose();
    },
  });

  if (!nextStep) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-md rounded-2xl overflow-hidden ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        <div className="flex items-center justify-between px-6 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Advance to Next Step</h2>
          <button onClick={onClose} className="p-1 rounded-lg hover:bg-[var(--bg-hover)]">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>
        <div className="p-6 space-y-4">
          <div className="p-3 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Current step will be marked complete:</p>
            <p className="text-sm font-medium mt-1" style={{ color: 'var(--text-primary)' }}>
              {STEP_DEFINITIONS[currentIndex]?.title}
            </p>
          </div>
          <div className="p-3 rounded-lg" style={{ backgroundColor: 'var(--gold-8)', border: '1px solid var(--gold-15)' }}>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Next step:</p>
            <p className="text-sm font-semibold mt-1" style={{ color: 'var(--gold)' }}>
              {nextStep.title}
            </p>
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Completion Notes (optional)</label>
            <textarea
              value={notes}
              onChange={e => setNotes(e.target.value)}
              rows={3}
              placeholder="Notes on completion of current step…"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none"
              style={inputStyle}
            />
          </div>
          {mutation.isError && <p className="text-xs text-red-400">Failed to advance step.</p>}
          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button onClick={() => mutation.mutate()} disabled={mutation.isPending}
              className="px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60 active:scale-[0.98]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              {mutation.isPending ? 'Advancing…' : 'Advance Step'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

// ─── Step Tracker ─────────────────────────────────────────────────────────────

function StepTracker({
  steps, currentStep, canWrite, projectId, caseId,
  onAddDocument,
}: {
  steps: any[]; currentStep: string; canWrite: boolean; projectId: string; caseId: string;
  onAddDocument: (docType: string, step: string) => void;
}) {
  const stepMap = Object.fromEntries(steps.map((s: any) => [s.step_key, s]));

  return (
    <div className="space-y-2">
      {STEP_DEFINITIONS.map((def, idx) => {
        const step      = stepMap[def.key];
        const stepStatus = step?.status ?? 'pending';
        const isCurrent  = def.key === currentStep;
        const isCompleted = stepStatus === 'completed';
        const isInProgress = stepStatus === 'in_progress';
        const colors    = STEP_STATUS_COLORS[isInProgress ? 'in_progress' : stepStatus] ?? STEP_STATUS_COLORS.pending;
        const actions   = STEP_ACTIONS[def.key] ?? [];

        return (
          <div
            key={def.key}
            className="rounded-xl transition-colors"
            style={{
              backgroundColor: isCurrent ? 'var(--gold-8)' : 'transparent',
              border: isCurrent ? '1px solid var(--gold-15)' : '1px solid transparent',
              padding: '10px 12px',
            }}
          >
            <div className="flex items-start gap-3">
              {/* Icon */}
              <div className="flex-shrink-0 flex flex-col items-center">
                <div className="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold"
                  style={{
                    backgroundColor: isCompleted ? 'rgba(34,197,94,0.15)' : isCurrent ? 'var(--gold-15)' : 'var(--bg-elevated)',
                    color: isCompleted ? '#4ade80' : isCurrent ? 'var(--gold)' : 'var(--text-muted)',
                  }}>
                  {isCompleted ? <CheckCircle2 size={14} /> : isCurrent ? <Activity size={12} /> : <span>{idx + 1}</span>}
                </div>
                {idx < STEP_DEFINITIONS.length - 1 && (
                  <div className="w-px h-3 mt-1" style={{ backgroundColor: isCompleted ? 'rgba(34,197,94,0.3)' : 'var(--border)' }} />
                )}
              </div>

              {/* Content */}
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="text-sm font-medium" style={{ color: isCurrent ? 'var(--gold)' : isCompleted ? 'var(--text-secondary)' : 'var(--text-primary)' }}>
                    {def.title}
                  </span>
                  <span className="text-xs px-2 py-0.5 rounded-full capitalize" style={{ backgroundColor: colors.bg, color: colors.text }}>
                    {isCurrent || isInProgress ? 'Current' : isCompleted ? 'Completed' : stepStatus}
                  </span>
                </div>

                {step?.due_date && (
                  <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Due: {formatDate(step.due_date)}</p>
                )}
                {step?.completed_at && (
                  <p className="text-xs mt-0.5" style={{ color: '#4ade80' }}>
                    ✓ Completed {formatDate(step.completed_at)}
                    {step.completed_by_user?.name ? ` by ${step.completed_by_user.name}` : ''}
                  </p>
                )}
                {step?.notes && (
                  <p className="text-xs mt-0.5 italic" style={{ color: 'var(--text-muted)' }}>{step.notes}</p>
                )}

                {/* Step actions — shown for current step; disabled for others */}
                {canWrite && actions.length > 0 && (
                  <div className="flex flex-wrap gap-1.5 mt-2">
                    {actions.map(action => {
                      const isActive = isInProgress && !action.ai && !action.placeholder;
                      return (
                        <button
                          key={action.label}
                          disabled={!isActive}
                          onClick={() => {
                            if (isActive && action.docType) {
                              onAddDocument(action.docType, def.key);
                            }
                          }}
                          title={action.ai ? 'AI feature — coming soon' : action.placeholder ? 'Coming soon' : undefined}
                          className="flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs transition-all"
                          style={{
                            backgroundColor: isActive ? 'var(--gold-15)' : 'var(--bg-elevated)',
                            color: isActive ? 'var(--gold)' : 'var(--text-muted)',
                            opacity: isInProgress ? 1 : 0.45,
                            cursor: isActive ? 'pointer' : 'default',
                            border: `1px solid ${isActive ? 'var(--gold-15)' : 'var(--border)'}`,
                          }}
                        >
                          {action.ai && <Zap size={10} />}
                          {action.docType && !action.ai && <Upload size={10} />}
                          {action.placeholder && !action.ai && <CheckCircle2 size={10} />}
                          {action.label}
                        </button>
                      );
                    })}
                  </div>
                )}
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
}

// ─── Activity Timeline ────────────────────────────────────────────────────────

function ActivityTimeline({ projectId, caseId }: { projectId: string; caseId: string }) {
  const { data } = useQuery({
    queryKey: ['adjudication-activity', caseId],
    queryFn: () =>
      api.get(`/projects/${projectId}/activities`, {
        params: { related_type: 'AdjudicationCase', related_id: caseId },
      }).then(r => r.data),
  });

  const activities: any[] = data?.data ?? [];

  const ACTIVITY_ICONS: Record<string, React.ReactNode> = {
    adjudication_created:        <Scale size={12} />,
    adjudication_step_advanced:  <ChevronRight size={12} />,
    adjudication_archived:       <Archive size={12} />,
    adjudication_status_changed: <Activity size={12} />,
    adjudication_document_added: <FileText size={12} />,
    adjudication_deleted:        <X size={12} />,
  };

  return (
    <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      <div className="flex items-center gap-2 px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
        <History size={14} style={{ color: 'var(--text-muted)' }} />
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Case Activity</h2>
      </div>
      {activities.length === 0 ? (
        <div className="p-6 text-center">
          <History size={24} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No activity recorded yet</p>
        </div>
      ) : (
        <div className="divide-y" style={{ borderColor: 'var(--border)' }}>
          {activities.map((act: any, i: number) => (
            <div key={act.id ?? i} className="flex items-start gap-3 px-5 py-3">
              <div className="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5"
                style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}>
                {ACTIVITY_ICONS[act.activity_type] ?? <Activity size={12} />}
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-xs font-medium" style={{ color: 'var(--text-primary)' }}>{act.title}</p>
                {act.description && (
                  <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{act.description}</p>
                )}
                <div className="flex items-center gap-2 mt-1">
                  {act.user?.name && (
                    <span className="text-xs flex items-center gap-1" style={{ color: 'var(--text-muted)' }}>
                      <User size={9} /> {act.user.name}
                    </span>
                  )}
                  <span className="text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>
                    {formatDate(act.created_at)}
                  </span>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

// ─── Linked Records ───────────────────────────────────────────────────────────

function LinkedRecordsPanel({ adjCase, projectId }: { adjCase: any; projectId: string }) {
  const records: { type: string; label: string; ref: string; href: string }[] = [];

  if (adjCase.contract) {
    records.push({
      type:  'Contract',
      label: adjCase.contract.title,
      ref:   adjCase.contract.reference_number ?? adjCase.contract.title,
      href:  `/app/projects/${projectId}/contracts`,
    });
  }
  if (adjCase.payment_application) {
    records.push({
      type:  'Payment Application',
      label: `Application #${adjCase.payment_application.application_number}`,
      ref:   `#${adjCase.payment_application.application_number}`,
      href:  `/app/projects/${projectId}/commercial`,
    });
  }
  if (adjCase.variation) {
    records.push({
      type:  'Variation',
      label: adjCase.variation.title,
      ref:   `Var #${adjCase.variation.variation_number}`,
      href:  `/app/projects/${projectId}/variations`,
    });
  }

  if (records.length === 0) return null;

  return (
    <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      <div className="flex items-center gap-2 px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
        <Link2 size={14} style={{ color: 'var(--text-muted)' }} />
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Linked Records</h2>
        <span className="text-xs ml-auto px-2 py-0.5 rounded-full" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}>
          {records.length}
        </span>
      </div>
      <div className="divide-y" style={{ borderColor: 'var(--border)' }}>
        {records.map(r => (
          <a key={r.type} href={r.href}
            className="flex items-center justify-between px-5 py-3 hover:bg-[var(--bg-hover)] transition-colors">
            <div>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{r.type}</p>
              <p className="text-xs font-medium mt-0.5 truncate max-w-[200px]" style={{ color: 'var(--text-primary)' }}>{r.label}</p>
            </div>
            <div className="flex items-center gap-2">
              <span className="text-[11px] font-mono" style={{ color: 'var(--text-muted)' }}>{r.ref}</span>
              <ExternalLink size={11} style={{ color: 'var(--text-muted)' }} />
            </div>
          </a>
        ))}
      </div>
    </div>
  );
}

// ─── Page ────────────────────────────────────────────────────────────────────

export default function AdjudicationCaseDetailPage() {
  const { id, caseId } = useParams<{ id: string; caseId: string }>();
  const router = useRouter();
  const qc = useQueryClient();
  const { canWrite } = useProjectPermissions();

  const [showAddDoc, setShowAddDoc]       = useState<{ open: boolean; docType?: string; step?: string }>({ open: false });
  const [showAddDeadline, setShowDeadline] = useState(false);
  const [showAdvance, setShowAdvance]     = useState(false);

  const { data: adjCase, isLoading } = useQuery({
    queryKey: ['adjudication-case', caseId],
    queryFn: () => api.get(`/projects/${id}/adjudication-cases/${caseId}`).then(r => r.data),
  });

  const { data: documents } = useQuery({
    queryKey: ['adjudication-documents', caseId],
    queryFn: () => api.get(`/projects/${id}/adjudication-cases/${caseId}/documents`).then(r => r.data),
    enabled: !!adjCase,
  });

  const { data: deadlines } = useQuery({
    queryKey: ['adjudication-deadlines', caseId],
    queryFn: () => api.get(`/projects/${id}/adjudication-cases/${caseId}/deadlines`).then(r => r.data),
    enabled: !!adjCase,
  });

  const deleteDocMutation = useMutation({
    mutationFn: (docId: number) => api.delete(`/projects/${id}/adjudication-documents/${docId}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['adjudication-documents', caseId] }),
  });

  const completeDeadlineMutation = useMutation({
    mutationFn: (dlId: number) => api.post(`/projects/${id}/adjudication-deadlines/${dlId}/complete`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['adjudication-deadlines', caseId] }),
  });

  const deleteDeadlineMutation = useMutation({
    mutationFn: (dlId: number) => api.delete(`/projects/${id}/adjudication-deadlines/${dlId}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['adjudication-deadlines', caseId] }),
  });

  const updateStatusMutation = useMutation({
    mutationFn: (status: string) =>
      api.post(`/projects/${id}/adjudication-cases/${caseId}/update-status`, { status }).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['adjudication-case', caseId] });
      qc.invalidateQueries({ queryKey: ['adjudication-activity', caseId] });
      toast.success('Case status updated');
    },
    onError: () => toast.error('Failed to update status'),
  });

  const archiveMutation = useMutation({
    mutationFn: () =>
      api.post(`/projects/${id}/adjudication-cases/${caseId}/archive`).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['adjudication-case', caseId] });
      qc.invalidateQueries({ queryKey: ['adjudication-activity', caseId] });
      qc.invalidateQueries({ queryKey: ['project-adjudication-cases', id] });
      toast.success('Case archived');
    },
    onError: () => toast.error('Failed to archive case'),
  });

  if (isLoading) {
    return (
      <div className="p-6 max-w-5xl mx-auto space-y-4">
        {[...Array(6)].map((_, i) => (
          <div key={i} className="h-16 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
        ))}
      </div>
    );
  }

  if (!adjCase) {
    return (
      <div className="p-6 max-w-5xl mx-auto text-center pt-24">
        <Scale size={40} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Adjudication case not found.</p>
        <button onClick={() => router.back()} className="mt-4 px-4 py-2 rounded-lg text-sm"
          style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
          Go Back
        </button>
      </div>
    );
  }

  const statusBadge = STATUS_COLORS[adjCase.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
  const stepDefs    = adjCase.steps ?? [];
  const allDocs     = documents ?? [];
  const allDeadlines = deadlines ?? [];

  const stepKeys  = STEP_DEFINITIONS.map(s => s.key);
  const currentIdx = stepKeys.indexOf(adjCase.current_step);
  const canAdvance = canWrite && currentIdx < stepKeys.length - 1 && adjCase.status !== 'closed';

  // Deadline computed status
  const computeDeadlineStatus = (dl: any) => {
    if (dl.completed_at) return 'completed';
    const due = new Date(dl.due_date);
    const now = new Date();
    const diff = Math.ceil((due.getTime() - now.getTime()) / (1000 * 60 * 60 * 24));
    if (diff < 0) return 'overdue';
    if (diff <= 3) return 'due_soon';
    return 'upcoming';
  };

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      {/* Modals */}
      {showAddDoc.open && (
        <AddDocumentModal
          caseId={caseId}
          projectId={id}
          defaultDocType={showAddDoc.docType}
          sourceStep={showAddDoc.step}
          onClose={() => setShowAddDoc({ open: false })}
        />
      )}
      {showAddDeadline && (
        <AddDeadlineModal caseId={caseId} projectId={id} caseCreatedAt={adjCase?.created_at} onClose={() => setShowDeadline(false)} />
      )}
      {showAdvance && (
        <AdvanceStepModal adjudicationCase={adjCase} projectId={id} onClose={() => setShowAdvance(false)} />
      )}

      {/* Back + Header */}
      <div>
        <button
          onClick={() => router.push(`/app/projects/${id}/adjudication`)}
          className="flex items-center gap-1.5 text-xs mb-4 rounded-lg transition-all hover:bg-[var(--bg-hover)] px-2 py-1"
          style={{ color: 'var(--text-muted)' }}
        >
          <ArrowLeft size={12} /> All Cases
        </button>

        <div className="flex items-start justify-between gap-4 flex-wrap">
          <div>
            <div className="flex items-center gap-3 flex-wrap">
              <div className="w-9 h-9 rounded-lg flex items-center justify-center" style={{ backgroundColor: 'var(--gold-15)' }}>
                <Scale size={16} style={{ color: 'var(--gold)' }} />
              </div>
              <div>
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="text-[11px] font-mono" style={{ color: 'var(--text-muted)' }}>{adjCase.case_number}</span>
                  <h1 className="text-xl font-bold" style={{ color: 'var(--text-primary)' }}>{adjCase.title}</h1>
                </div>
                <div className="flex items-center gap-2 mt-1 flex-wrap">
                  <span className="text-xs px-2 py-0.5 rounded-full" style={{ backgroundColor: statusBadge.bg, color: statusBadge.text }}>
                    {STATUS_DISPLAY[adjCase.status] ?? adjCase.status?.replace(/_/g, ' ').replace(/\b\w/g, (c: string) => c.toUpperCase())}
                  </span>
                  {adjCase.dispute_type && (
                    <span className="text-xs px-2 py-0.5 rounded-full capitalize"
                      style={{ backgroundColor: 'rgba(59,130,246,0.1)', color: '#60a5fa' }}>
                      {adjCase.dispute_type.replace(/_/g, ' ')}
                    </span>
                  )}
                  {adjCase.status === 'archived' && (
                    <span className="text-xs flex items-center gap-1" style={{ color: 'var(--text-muted)' }}>
                      <Archive size={10} /> Archived
                    </span>
                  )}
                  {adjCase.claim_amount && (
                    <span className="text-xs font-medium tabular-nums" style={{ color: 'var(--text-secondary)' }}>
                      {adjCase.currency} {Number(adjCase.claim_amount).toLocaleString()}
                    </span>
                  )}
                  {adjCase.created_at && (
                    <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                      Created {formatDate(adjCase.created_at)}
                    </span>
                  )}
                </div>
              </div>
            </div>
          </div>
          {/* Action buttons */}
          <div className="flex items-center gap-2 flex-wrap">
            <PromptActionButton
              label="Generate Prompt"
              module="Adjudication"
              recordType="adjudication_case"
              recordId={adjCase.id}
              projectId={id}
            />
            {canAdvance && (
              <button
                onClick={() => setShowAdvance(true)}
                className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90 active:scale-[0.98]"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
              >
                <ChevronRight size={14} /> Advance Step
              </button>
            )}
            {canWrite && adjCase.status !== 'closed' && adjCase.status !== 'archived' && (
              <button
                onClick={() => { if (confirm('Mark this case as closed?')) updateStatusMutation.mutate('closed'); }}
                className="px-4 py-2 rounded-lg text-sm transition-colors hover:bg-[var(--bg-hover)]"
                style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}
              >
                Close Case
              </button>
            )}
            {canWrite && adjCase.status !== 'archived' && (
              <button
                onClick={() => { if (confirm('Archive this case? It will not be deleted.')) archiveMutation.mutate(); }}
                className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm transition-colors hover:bg-[var(--bg-hover)]"
                style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}
              >
                <Archive size={13} /> Archive
              </button>
            )}
          </div>
        </div>
      </div>

      {/* Case metadata */}
      <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
        {[
          { label: 'Claimant',   value: adjCase.claimant_name },
          { label: 'Respondent', value: adjCase.respondent_name },
          { label: 'Dispute Type', value: adjCase.dispute_type?.replace(/_/g, ' ').replace(/\b\w/g, (c: string) => c.toUpperCase()) },
          { label: 'Current Step', value: STEP_DEFINITIONS.find(s => s.key === adjCase.current_step)?.title ?? adjCase.current_step },
          adjCase.contract ? { label: 'Contract', value: adjCase.contract.reference_number ?? adjCase.contract.title } : null,
          adjCase.payment_application ? { label: 'Payment Application', value: `App #${adjCase.payment_application.application_number}` } : null,
          adjCase.variation ? { label: 'Variation', value: `Var #${adjCase.variation.variation_number} – ${adjCase.variation.title}` } : null,
          adjCase.creator ? { label: 'Created By', value: adjCase.creator.name } : null,
        ].filter(Boolean).map((item: any, i: number) => (
          <div key={item.label} className="p-3 rounded-xl ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: `${Math.min(i * 45, 360)}ms` }}>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{item.label}</p>
            <p className="text-sm font-medium mt-1 truncate" style={{ color: 'var(--text-primary)' }}>{item.value ?? '—'}</p>
          </div>
        ))}
      </div>

      {adjCase.summary && (
        <div className="p-4 rounded-xl" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <p className="text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Summary</p>
          <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>{adjCase.summary}</p>
        </div>
      )}

      {/* Main content: 2 columns */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Step tracker — left 2/3 */}
        <div className="lg:col-span-2 space-y-4">
          <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <div className="px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
              <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>8-Step Workflow</h2>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Track adjudication progress through each stage</p>
            </div>
            <div className="p-4">
              <StepTracker
                steps={stepDefs}
                currentStep={adjCase.current_step}
                canWrite={canWrite}
                projectId={id}
                caseId={caseId}
                onAddDocument={(docType, step) => setShowAddDoc({ open: true, docType, step })}
              />
            </div>
          </div>

          {/* Documents */}
          <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <div className="flex items-center justify-between px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
              <div>
                <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Documents</h2>
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{allDocs.length} document{allDocs.length !== 1 ? 's' : ''}</p>
              </div>
              {canWrite && (
                <button
                  onClick={() => setShowAddDoc({ open: true })}
                  className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium"
                  style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}
                >
                  <Plus size={12} /> Add Document
                </button>
              )}
            </div>
            <div className="divide-y" style={{ borderColor: 'var(--border)' }}>
              {allDocs.length === 0 ? (
                <div className="p-8 text-center">
                  <FileText size={28} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No documents yet</p>
                  {canWrite && (
                    <button onClick={() => setShowAddDoc({ open: true })}
                      className="mt-3 px-3 py-1.5 rounded-lg text-xs active:scale-[0.98]"
                      style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
                      Add First Document
                    </button>
                  )}
                </div>
              ) : allDocs.map((doc: any) => {
                const docStatusBadge = DOC_STATUS_COLORS[doc.status] ?? DOC_STATUS_COLORS.draft;
                return (
                  <div key={doc.id} className="flex items-center justify-between px-5 py-3 hover:bg-[var(--bg-hover)] transition-colors">
                    <div className="flex items-center gap-3 flex-1 min-w-0">
                      <div className="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0"
                        style={{ backgroundColor: 'rgba(59,130,246,0.1)' }}>
                        <FileText size={12} style={{ color: '#60a5fa' }} />
                      </div>
                      <div className="min-w-0">
                        <p className="text-xs font-medium truncate" style={{ color: 'var(--text-primary)' }}>{doc.title}</p>
                        <div className="flex items-center gap-2 mt-0.5 flex-wrap">
                          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                            {DOC_TYPE_LABELS[doc.document_type] ?? doc.document_type}
                          </span>
                          {doc.ai_generated && (
                            <span className="text-xs px-1.5 py-0.5 rounded"
                              style={{ backgroundColor: 'rgba(168,85,247,0.1)', color: '#c084fc' }}>AI Draft</span>
                          )}
                          {doc.category && (
                            <span className="text-xs px-1.5 py-0.5 rounded"
                              style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}>
                              {DOC_CATEGORY_LABELS[doc.category] ?? doc.category}
                            </span>
                          )}
                          <span className="text-xs px-2 py-0.5 rounded-full" style={{ backgroundColor: docStatusBadge.bg, color: docStatusBadge.text }}>
                            {doc.status?.replace(/_/g, ' ')}
                          </span>
                          {doc.uploaded_by_user?.name && (
                            <span className="text-xs flex items-center gap-1" style={{ color: 'var(--text-muted)' }}>
                              <User size={9} /> {doc.uploaded_by_user.name}
                            </span>
                          )}
                        </div>
                      </div>
                    </div>
                    {canWrite && (
                      <button
                        onClick={() => { if (confirm('Remove this document?')) deleteDocMutation.mutate(doc.id); }}
                        className="p-1 rounded hover:bg-[var(--bg-hover)] ml-2 flex-shrink-0"
                        style={{ color: 'var(--text-muted)' }}
                      >
                        <X size={12} />
                      </button>
                    )}
                  </div>
                );
              })}
            </div>
          </div>

          {/* Linked Records */}
          <LinkedRecordsPanel adjCase={adjCase} projectId={id} />

          {/* Activity Timeline */}
          <ActivityTimeline projectId={id} caseId={caseId} />
        </div>

        {/* Right panel: deadlines + key dates */}
        <div className="space-y-4">
          {/* Deadlines */}
          <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <div className="flex items-center justify-between px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
              <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Deadlines</h2>
              {canWrite && (
                <button
                  onClick={() => setShowDeadline(true)}
                  className="flex items-center gap-1 text-xs"
                  style={{ color: 'var(--gold)' }}
                >
                  <Plus size={12} /> Add
                </button>
              )}
            </div>
            <div className="divide-y" style={{ borderColor: 'var(--border)' }}>
              {allDeadlines.length === 0 ? (
                <div className="p-6 text-center">
                  <Calendar size={24} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No deadlines tracked</p>
                </div>
              ) : allDeadlines.map((dl: any) => {
                const dlStatus = computeDeadlineStatus(dl);
                const dlColors = DEADLINE_STATUS_COLORS[dlStatus] ?? DEADLINE_STATUS_COLORS.upcoming;
                return (
                  <div key={dl.id} className="px-4 py-3">
                    <div className="flex items-start justify-between gap-2">
                      <div className="flex-1 min-w-0">
                        <p className="text-xs font-medium truncate" style={{ color: 'var(--text-primary)' }}>{dl.title}</p>
                        <p className="text-xs mt-0.5 tabular-nums" style={{ color: 'var(--text-muted)' }}>
                          {dl.due_date ? formatDate(dl.due_date) : '—'}
                        </p>
                        {(() => {
                          const daysInfo = dl.completed_at ? null : computeDaysRemaining(dl.due_date);
                          return (
                            <div className="flex items-center gap-1.5 mt-1 flex-wrap">
                              <span className="inline-block text-xs px-2 py-0.5 rounded-full capitalize"
                                style={{ backgroundColor: dlColors.bg, color: dlColors.text }}>
                                {dlStatus.replace(/_/g, ' ')}
                              </span>
                              {daysInfo && (
                                <span className="text-xs flex items-center gap-1"
                                  style={{ color: DAYS_STATUS_COLORS[daysInfo.status].text }}>
                                  {daysInfo.status === 'overdue' && <AlertTriangle size={9} />}
                                  {daysInfo.label}
                                </span>
                              )}
                            </div>
                          );
                        })()}
                      </div>
                      {canWrite && (
                        <div className="flex gap-1 flex-shrink-0">
                          {dlStatus !== 'completed' && (
                            <button
                              onClick={() => completeDeadlineMutation.mutate(dl.id)}
                              className="p-1 rounded hover:bg-[var(--bg-hover)]"
                              title="Mark complete"
                              style={{ color: '#4ade80' }}
                            >
                              <CheckCircle2 size={13} />
                            </button>
                          )}
                          <button
                            onClick={() => deleteDeadlineMutation.mutate(dl.id)}
                            className="p-1 rounded hover:bg-[var(--bg-hover)]"
                            style={{ color: 'var(--text-muted)' }}
                          >
                            <X size={12} />
                          </button>
                        </div>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>

          {/* Key case dates */}
          <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <div className="px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
              <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Key Dates</h2>
            </div>
            <div className="p-4 space-y-2">
              {[
                { label: 'Notice of Dispute',      value: adjCase.notice_of_dispute_date },
                { label: 'Notice of Adjudication', value: adjCase.notice_of_adjudication_date },
                { label: 'Referral Due',            value: adjCase.referral_due_date },
                { label: 'Response Due',            value: adjCase.response_due_date },
                { label: 'Decision Due',            value: adjCase.decision_due_date },
                { label: 'Decision Received',       value: adjCase.decision_received_date },
                { label: 'Enforcement Deadline',    value: adjCase.enforcement_deadline },
              ].filter(d => d.value).map(d => (
                <div key={d.label} className="flex justify-between items-center py-1">
                  <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{d.label}</span>
                  <span className="text-xs font-medium tabular-nums" style={{ color: 'var(--text-secondary)' }}>
                    {formatDate(d.value)}
                  </span>
                </div>
              ))}
              {!adjCase.notice_of_dispute_date && !adjCase.referral_due_date && (
                <p className="text-xs text-center py-2" style={{ color: 'var(--text-muted)' }}>No key dates set</p>
              )}
            </div>
          </div>

          {/* AI hooks panel */}
          <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <div className="px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
              <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>AI Assistant</h2>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Coming soon</p>
            </div>
            <div className="p-4 space-y-2">
              {[
                'Summarise Dispute',
                'Draft Notice of Dispute',
                'Draft Notice of Adjudication',
                'Summarise Referral Documents',
                'Analyse Response',
                'Draft Enforcement Letter',
              ].map(label => (
                <button
                  key={label}
                  disabled
                  className="w-full text-left px-3 py-2 rounded-lg text-xs transition-colors opacity-50 cursor-not-allowed"
                  style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}
                >
                  {label}
                </button>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
