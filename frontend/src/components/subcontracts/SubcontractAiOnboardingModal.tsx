'use client';

import { useEffect, useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { X, Upload, Sparkles, AlertTriangle, CheckCircle, Loader2 } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '@/lib/api';
import Button from '@/components/ui/Button';
import Section from '@/components/ai/Section';
import AnalysisLoadingDisplay from '@/components/ai/AnalysisLoadingDisplay';
import { cn } from '@/lib/utils';
import { getErrorMessage } from '@/lib/getErrorMessage';

// ── types ──────────────────────────────────────────────────────────────────

interface SubcontractGeneral {
  subcontract_title: string | null;
  subcontract_reference: string | null;
  standard_form: string | null;
  detected_trade: string | null;
  detected_trade_freeform: string | null;
  detected_trade_confidence: string | null;
}
interface SubcontractContractor {
  name: string | null;
  contact_name: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  company_registration_number: string | null;
  vat_number: string | null;
}
interface SubcontractCommercial {
  subcontract_sum: string | null;
  retention_percentage: string | null;
  liquidated_damages: string | null;
  payment_terms_days: string | null;
  payment_frequency: string | null;
  due_date_offset_days: string | null;
  final_date_offset_days: string | null;
  payment_notice_offset_days: string | null;
  pay_less_notice_offset_days: string | null;
}
interface SubcontractKeyDates {
  letter_of_intent_date: string | null;
  award_date: string | null;
  execution_date: string | null;
  commencement_date: string | null;
  completion_date: string | null;
  defects_liability_end_date: string | null;
}
interface SubcontractMilestone { name: string | null; date: string | null; notes: string | null; }

interface SubcontractAnalysisData {
  meta?: { confidence?: string; extraction_notes?: string | null };
  general: SubcontractGeneral;
  contractor: SubcontractContractor;
  commercial: SubcontractCommercial;
  key_dates: SubcontractKeyDates;
  programme_milestones: SubcontractMilestone[];
  missing_information?: string[];
}

interface Props {
  isOpen: boolean;
  onClose: () => void;
  tradePackage: { id: number; name: string; is_custom?: boolean };
  projectId: string;
  onConfirmed: () => void;
}

// ── field helpers ────────────────────────────────────────────────────────

function TextField({ label, value, onChange }: { label: string; value: string | null; onChange: (v: string) => void }) {
  return (
    <div>
      <label className="mb-1 block text-xs" style={{ color: 'var(--text-muted)' }}>{label}</label>
      <input
        type="text"
        value={value ?? ''}
        onChange={e => onChange(e.target.value)}
        className="w-full rounded-lg px-3 py-2 text-sm outline-none"
        style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
      />
    </div>
  );
}

function DateField({ label, value, onChange }: { label: string; value: string | null; onChange: (v: string) => void }) {
  return (
    <div>
      <label className="mb-1 block text-xs" style={{ color: 'var(--text-muted)' }}>{label}</label>
      <input
        type="date"
        value={value ?? ''}
        onChange={e => onChange(e.target.value)}
        className="w-full rounded-lg px-3 py-2 text-sm outline-none"
        style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
      />
    </div>
  );
}

const EMPTY_DATA: SubcontractAnalysisData = {
  general: { subcontract_title: null, subcontract_reference: null, standard_form: null, detected_trade: null, detected_trade_freeform: null, detected_trade_confidence: null },
  contractor: { name: null, contact_name: null, email: null, phone: null, address: null, company_registration_number: null, vat_number: null },
  commercial: { subcontract_sum: null, retention_percentage: null, liquidated_damages: null, payment_terms_days: null, payment_frequency: null, due_date_offset_days: null, final_date_offset_days: null, payment_notice_offset_days: null, pay_less_notice_offset_days: null },
  key_dates: { letter_of_intent_date: null, award_date: null, execution_date: null, commencement_date: null, completion_date: null, defects_liability_end_date: null },
  programme_milestones: [],
  missing_information: [],
};

// ── component ──────────────────────────────────────────────────────────────

export default function SubcontractAiOnboardingModal({ isOpen, onClose, tradePackage, projectId, onConfirmed }: Props) {
  const queryClient = useQueryClient();
  const fileInputRef = useRef<HTMLInputElement>(null);

  const [step, setStep] = useState<'upload' | 'analysing' | 'reviewing' | 'error'>('upload');
  const [analysisId, setAnalysisId] = useState<number | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [data, setData] = useState<SubcontractAnalysisData>(EMPTY_DATA);
  const [openSections, setOpenSections] = useState<Record<string, boolean>>({
    general: true, contractor: true, commercial: true, dates: true, milestones: true,
  });

  function toggleSection(key: string) {
    setOpenSections(prev => ({ ...prev, [key]: !prev[key] }));
  }

  const loadAnalysisIntoForm = (analysis: any) => {
    const raw = analysis?.raw_response_json;
    setData({
      general: { ...EMPTY_DATA.general, ...(raw?.general ?? {}) },
      contractor: { ...EMPTY_DATA.contractor, ...(raw?.contractor ?? {}) },
      commercial: { ...EMPTY_DATA.commercial, ...(raw?.commercial ?? {}) },
      key_dates: { ...EMPTY_DATA.key_dates, ...(raw?.key_dates ?? {}) },
      programme_milestones: Array.isArray(raw?.programme_milestones) ? raw.programme_milestones : [],
      missing_information: raw?.missing_information ?? [],
    });
    setAnalysisId(analysis.id);
    setStep('reviewing');
  };

  const uploadMutation = useMutation({
    mutationFn: async (file: File) => {
      const fd = new FormData();
      fd.append('file', file);
      fd.append('document_type', 'executed_contract');
      fd.append('title', file.name);
      const uploadRes = await api.post(`/trade-packages/${tradePackage.id}/upload`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      const fileUploadId = uploadRes.data.id;

      // The upload above has already succeeded and committed by this point
      // — a failure starting analysis (AI disabled, already in progress,
      // etc.) must never be reported as if nothing happened at all. Tagging
      // the error here lets onError below tell the truth about which half
      // failed, rather than a single generic "failed to start" message that
      // could just as easily describe a lost upload.
      try {
        const startRes = await api.post(`/trade-packages/${tradePackage.id}/ai-analysis`, {
          file_upload_id: fileUploadId,
        });
        return startRes.data;
      } catch (startErr) {
        throw Object.assign(new Error('analysis_start_failed'), { cause: startErr, uploadSucceeded: true });
      }
    },
    onSuccess: (res) => {
      if (res.existing_analysis) {
        toast('A completed analysis already exists for this trade package. Showing it.', { icon: 'ℹ️' });
        loadAnalysisIntoForm(res.existing_analysis);
        return;
      }
      setAnalysisId(res.data.id);
      setStep('analysing');
    },
    onError: (err: unknown) => {
      const uploadSucceeded = err instanceof Error && (err as Error & { uploadSucceeded?: boolean }).uploadSucceeded === true;
      const cause = uploadSucceeded ? (err as Error & { cause?: unknown }).cause : err;
      toast.error(
        uploadSucceeded
          ? getErrorMessage(cause, "We uploaded the document, but couldn't start the analysis. The document is still available. Try uploading it again to retry.")
          : getErrorMessage(err, 'Failed to upload the document.')
      );
    },
  });

  // Poll until complete
  useEffect(() => {
    if (step !== 'analysing' || !analysisId) return;
    const interval = setInterval(async () => {
      try {
        const res = await api.get(`/trade-package-ai-analyses/${analysisId}`);
        const a = res.data?.data;
        if (a?.status === 'completed') {
          loadAnalysisIntoForm(a);
        } else if (a?.status === 'failed') {
          setErrorMessage(a.error_message ?? 'Analysis failed.');
          setStep('error');
        }
      } catch {
        // transient — keep polling
      }
    }, 3000);
    return () => clearInterval(interval);
  }, [step, analysisId]); // eslint-disable-line react-hooks/exhaustive-deps

  const confirmMutation = useMutation({
    mutationFn: () => api.post(`/trade-package-ai-analyses/${analysisId}/confirm`, { confirmed_data: data }).then(r => r.data),
    onSuccess: () => {
      toast.success('Subcontract analysis confirmed, trade package updated.');
      queryClient.invalidateQueries({ queryKey: ['project-subcontracts', projectId] });
      queryClient.invalidateQueries({ queryKey: ['trade-package-workspace', projectId, String(tradePackage.id)] });
      onConfirmed();
      onClose();
    },
    onError: (err: unknown) => toast.error(getErrorMessage(err, 'Failed to confirm analysis.')),
  });

  const cancelMutation = useMutation({
    mutationFn: () => api.post(`/trade-package-ai-analyses/${analysisId}/cancel`),
    onSuccess: (res: any) => {
      toast.success(res?.data?.message ?? 'Analysis cancelled.');
      onClose();
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Could not cancel the analysis. It may already have finished.')),
  });

  const retryMutation = useMutation({
    mutationFn: () => api.post(`/trade-packages/${tradePackage.id}/ai-analysis`, { force_new: true }),
    onSuccess: (res) => {
      setAnalysisId(res.data.data.id);
      setErrorMessage(null);
      setStep('analysing');
    },
    onError: (err: unknown) => toast.error(getErrorMessage(err, 'Failed to restart analysis.')),
  });

  // Genuine exit animation before unmount, matching components/ui/Modal.tsx's
  // own close() pattern — kept as a custom layout (wide, multi-section,
  // step-driven upload/analysing/reviewing/error flow) rather than migrating
  // to the shared <Modal>, which is fixed at max-w-md. Blocked while an
  // upload/analysis/confirm is actually in flight, same as Modal's `busy`.
  const busy = uploadMutation.isPending || step === 'analysing' || confirmMutation.isPending;
  const [closing, setClosing] = useState(false);
  const close = () => {
    if (busy || closing) return;
    setClosing(true);
    window.setTimeout(onClose, 150);
  };

  useEffect(() => {
    const handleKey = (e: KeyboardEvent) => { if (e.key === 'Escape') close(); };
    window.addEventListener('keydown', handleKey);
    return () => window.removeEventListener('keydown', handleKey);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [busy, closing]);

  if (!isOpen) return null;

  const detectedTrade = data.general.detected_trade;
  const isOther = detectedTrade === 'Other';
  // Custom packages have no standard catalogue name to compare against — the AI's
  // "detected_trade" is constrained to the standard list + "Other", so a custom
  // package would almost always show as a false mismatch. Skip the check entirely
  // for custom packages rather than flag something we can't reliably verify.
  const mismatch = !tradePackage.is_custom
    && !!detectedTrade
    && detectedTrade.toLowerCase() !== tradePackage.name.toLowerCase()
    && !isOther;
  const showOtherNotice = !tradePackage.is_custom && isOther && !!data.general.detected_trade_freeform;

  return (
    <div
      className={cn('fixed inset-0 z-50 flex items-center justify-center p-4', closing ? 'ss-modal-overlay-out' : 'ss-modal-overlay-in')}
      style={{ backgroundColor: 'rgba(10,10,10,0.55)', backdropFilter: 'blur(3px)', WebkitBackdropFilter: 'blur(3px)' }}
      onClick={e => { if (e.target === e.currentTarget) close(); }}
    >
      <div
        className={cn('w-full max-w-2xl rounded-2xl flex flex-col', closing ? 'ss-modal-panel-out' : 'ss-modal-panel-in')}
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)', maxHeight: '92vh' }}
      >
        {/* Header */}
        <div className="flex items-center justify-between p-5 flex-shrink-0" style={{ borderBottom: '1px solid var(--border)' }}>
          <div className="flex items-center gap-2">
            <Sparkles size={16} style={{ color: 'var(--gold)' }} />
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Subcontract AI Onboarding</h2>
            <span className="text-xs px-2 py-0.5 rounded-full font-medium" style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}>
              {tradePackage.name}
            </span>
          </div>
          {!busy && (
            <button onClick={close} aria-label="Close" className="transition-opacity hover:opacity-70">
              <X size={18} style={{ color: 'var(--text-muted)' }} />
            </button>
          )}
        </div>

        <div className="overflow-y-auto p-5 space-y-5">
          {step === 'upload' && (
            <div className="space-y-4">
              <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>
                Upload the executed subcontract. AI will extract commercial terms, key dates, and programme milestones for you to review before anything is saved.
              </p>
              <div
                className="rounded-xl border-2 border-dashed p-10 text-center cursor-pointer transition-colors hover:bg-[var(--bg-hover)]"
                style={{ borderColor: 'var(--border)' }}
                onClick={() => fileInputRef.current?.click()}
                onDragOver={e => e.preventDefault()}
                onDrop={e => {
                  e.preventDefault();
                  const file = e.dataTransfer.files?.[0];
                  if (file) uploadMutation.mutate(file);
                }}
              >
                <Upload size={28} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
                <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
                  {uploadMutation.isPending ? 'Uploading…' : 'Click to upload or drag and drop'}
                </p>
                <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>PDF, DOCX, or TXT</p>
                <input
                  ref={fileInputRef}
                  type="file"
                  accept=".pdf,.docx,.doc,.txt"
                  className="hidden"
                  disabled={uploadMutation.isPending}
                  onChange={e => {
                    const file = e.target.files?.[0];
                    if (file) uploadMutation.mutate(file);
                  }}
                />
              </div>
            </div>
          )}

          {step === 'analysing' && (
            <>
              <AnalysisLoadingDisplay
                messages={[
                  { at: 0, text: 'Reading subcontract document…' },
                  { at: 10, text: 'Identifying the trade and contractor…' },
                  { at: 25, text: 'Extracting commercial terms…' },
                  { at: 45, text: 'Finding programme dates and milestones…' },
                  { at: 70, text: 'Almost there, finalising results…' },
                ]}
                caption="AI is reading the subcontract. You can leave this open."
              />
              <div className="flex justify-center">
                <button
                  onClick={() => { if (window.confirm('Cancel this analysis?')) cancelMutation.mutate(undefined); }}
                  className="text-xs px-3 py-1.5 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
                  style={{ color: 'var(--text-muted)' }}
                >
                  Cancel analysis
                </button>
              </div>
            </>
          )}

          {step === 'error' && (
            <div className="space-y-4">
              <div className="rounded-xl p-4 flex items-start gap-3" style={{ backgroundColor: 'rgba(239,68,68,0.08)', border: '1px solid rgba(239,68,68,0.22)' }}>
                <AlertTriangle size={18} style={{ color: '#f87171' }} className="flex-shrink-0 mt-0.5" />
                <div>
                  <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Analysis failed</p>
                  <p className="text-xs mt-1" style={{ color: 'var(--text-secondary)' }}>{errorMessage}</p>
                </div>
              </div>
              <div className="flex justify-end gap-3">
                <button onClick={close} className="rounded-lg px-4 py-2 text-sm transition-opacity hover:opacity-80" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
                  Close
                </button>
                <Button onClick={() => retryMutation.mutate()} disabled={retryMutation.isPending}>
                  {retryMutation.isPending ? 'Retrying…' : 'Retry analysis'}
                </Button>
              </div>
            </div>
          )}

          {step === 'reviewing' && (
            <div className="space-y-4">
              <div className="rounded-xl p-3" style={{ backgroundColor: 'rgba(234,179,8,0.08)', border: '1px solid rgba(234,179,8,0.2)' }}>
                <p className="text-xs" style={{ color: 'var(--text-secondary)' }}>
                  Review the extracted information below and correct anything that's wrong. Nothing is saved to this Trade Package until you confirm.
                </p>
              </div>

              {mismatch && (
                <div className="rounded-xl p-3 flex items-start gap-3" style={{ backgroundColor: 'rgba(239,68,68,0.08)', border: '1px solid rgba(239,68,68,0.22)' }}>
                  <AlertTriangle size={16} style={{ color: '#f87171' }} className="flex-shrink-0 mt-0.5" />
                  <p className="text-xs" style={{ color: 'var(--text-secondary)' }}>
                    You created this package as <strong>{tradePackage.name}</strong>, but this subcontract reads as a <strong>{detectedTrade}</strong> agreement.
                    Double-check this is the right subcontract before confirming. The Trade Package will not be switched automatically.
                  </p>
                </div>
              )}
              {showOtherNotice && (
                <div className="rounded-xl p-3 flex items-start gap-3" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
                  <AlertTriangle size={16} style={{ color: 'var(--text-muted)' }} className="flex-shrink-0 mt-0.5" />
                  <p className="text-xs" style={{ color: 'var(--text-secondary)' }}>
                    AI couldn't match this subcontract to a known standard trade, it reads as: <strong>{data.general.detected_trade_freeform}</strong>. Confirm this still belongs under <strong>{tradePackage.name}</strong> before continuing.
                  </p>
                </div>
              )}

              <Section title="General Information" open={openSections.general} onToggle={() => toggleSection('general')}>
                <div className="grid grid-cols-2 gap-3">
                  <TextField label="Subcontract Title" value={data.general.subcontract_title} onChange={v => setData(d => ({ ...d, general: { ...d.general, subcontract_title: v } }))} />
                  <TextField label="Subcontract Reference" value={data.general.subcontract_reference} onChange={v => setData(d => ({ ...d, general: { ...d.general, subcontract_reference: v } }))} />
                  <TextField label="Standard Form" value={data.general.standard_form} onChange={v => setData(d => ({ ...d, general: { ...d.general, standard_form: v } }))} />
                </div>
              </Section>

              <Section title="Contractor" open={openSections.contractor} onToggle={() => toggleSection('contractor')}>
                <div className="grid grid-cols-2 gap-3">
                  <TextField label="Name" value={data.contractor.name} onChange={v => setData(d => ({ ...d, contractor: { ...d.contractor, name: v } }))} />
                  <TextField label="Contact Name" value={data.contractor.contact_name} onChange={v => setData(d => ({ ...d, contractor: { ...d.contractor, contact_name: v } }))} />
                  <TextField label="Email" value={data.contractor.email} onChange={v => setData(d => ({ ...d, contractor: { ...d.contractor, email: v } }))} />
                  <TextField label="Phone" value={data.contractor.phone} onChange={v => setData(d => ({ ...d, contractor: { ...d.contractor, phone: v } }))} />
                  <TextField label="Address" value={data.contractor.address} onChange={v => setData(d => ({ ...d, contractor: { ...d.contractor, address: v } }))} />
                  <TextField label="Company Reg. No." value={data.contractor.company_registration_number} onChange={v => setData(d => ({ ...d, contractor: { ...d.contractor, company_registration_number: v } }))} />
                  <TextField label="VAT Number" value={data.contractor.vat_number} onChange={v => setData(d => ({ ...d, contractor: { ...d.contractor, vat_number: v } }))} />
                </div>
              </Section>

              <Section title="Commercial &amp; Payment Rules" open={openSections.commercial} onToggle={() => toggleSection('commercial')}>
                <div className="grid grid-cols-2 gap-3">
                  <TextField label="Subcontract Sum" value={data.commercial.subcontract_sum} onChange={v => setData(d => ({ ...d, commercial: { ...d.commercial, subcontract_sum: v } }))} />
                  <TextField label="Retention %" value={data.commercial.retention_percentage} onChange={v => setData(d => ({ ...d, commercial: { ...d.commercial, retention_percentage: v } }))} />
                  <TextField label="Liquidated Damages" value={data.commercial.liquidated_damages} onChange={v => setData(d => ({ ...d, commercial: { ...d.commercial, liquidated_damages: v } }))} />
                  <TextField label="Payment Terms (days)" value={data.commercial.payment_terms_days} onChange={v => setData(d => ({ ...d, commercial: { ...d.commercial, payment_terms_days: v } }))} />
                  <TextField label="Payment Frequency" value={data.commercial.payment_frequency} onChange={v => setData(d => ({ ...d, commercial: { ...d.commercial, payment_frequency: v } }))} />
                  <TextField label="Due Date Offset (days)" value={data.commercial.due_date_offset_days} onChange={v => setData(d => ({ ...d, commercial: { ...d.commercial, due_date_offset_days: v } }))} />
                  <TextField label="Final Date Offset (days)" value={data.commercial.final_date_offset_days} onChange={v => setData(d => ({ ...d, commercial: { ...d.commercial, final_date_offset_days: v } }))} />
                  <TextField label="Payment Notice Offset (days)" value={data.commercial.payment_notice_offset_days} onChange={v => setData(d => ({ ...d, commercial: { ...d.commercial, payment_notice_offset_days: v } }))} />
                  <TextField label="Pay Less Notice Offset (days)" value={data.commercial.pay_less_notice_offset_days} onChange={v => setData(d => ({ ...d, commercial: { ...d.commercial, pay_less_notice_offset_days: v } }))} />
                </div>
              </Section>

              <Section title="Key Dates" open={openSections.dates} onToggle={() => toggleSection('dates')}>
                <div className="grid grid-cols-2 gap-3">
                  <DateField label="Letter of Intent" value={data.key_dates.letter_of_intent_date} onChange={v => setData(d => ({ ...d, key_dates: { ...d.key_dates, letter_of_intent_date: v } }))} />
                  <DateField label="Award Date" value={data.key_dates.award_date} onChange={v => setData(d => ({ ...d, key_dates: { ...d.key_dates, award_date: v } }))} />
                  <DateField label="Execution Date" value={data.key_dates.execution_date} onChange={v => setData(d => ({ ...d, key_dates: { ...d.key_dates, execution_date: v } }))} />
                  <DateField label="Commencement" value={data.key_dates.commencement_date} onChange={v => setData(d => ({ ...d, key_dates: { ...d.key_dates, commencement_date: v } }))} />
                  <DateField label="Completion" value={data.key_dates.completion_date} onChange={v => setData(d => ({ ...d, key_dates: { ...d.key_dates, completion_date: v } }))} />
                  <DateField label="Defects Liability End" value={data.key_dates.defects_liability_end_date} onChange={v => setData(d => ({ ...d, key_dates: { ...d.key_dates, defects_liability_end_date: v } }))} />
                </div>
              </Section>

              <Section title={`Programme Milestones (${data.programme_milestones.length})`} open={openSections.milestones} onToggle={() => toggleSection('milestones')}>
                {data.programme_milestones.length === 0 ? (
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No named milestones found beyond commencement/completion.</p>
                ) : (
                  <div className="space-y-3">
                    {data.programme_milestones.map((m, i) => (
                      <div key={i} className="grid grid-cols-2 gap-3 pb-3" style={{ borderBottom: i < data.programme_milestones.length - 1 ? '1px solid var(--border)' : undefined }}>
                        <TextField label="Milestone" value={m.name} onChange={v => setData(d => ({ ...d, programme_milestones: d.programme_milestones.map((pm, idx) => idx === i ? { ...pm, name: v } : pm) }))} />
                        <DateField label="Date" value={m.date} onChange={v => setData(d => ({ ...d, programme_milestones: d.programme_milestones.map((pm, idx) => idx === i ? { ...pm, date: v } : pm) }))} />
                      </div>
                    ))}
                  </div>
                )}
              </Section>

              {!!data.missing_information?.length && (
                <Section title={`Missing Information (${data.missing_information.length})`} open={!!openSections.missing} onToggle={() => toggleSection('missing')}>
                  <ul className="space-y-1">
                    {data.missing_information.map((m, i) => (
                      <li key={i} className="text-xs flex items-start gap-2" style={{ color: 'var(--text-muted)' }}>
                        <span>•</span><span>{m}</span>
                      </li>
                    ))}
                  </ul>
                </Section>
              )}

              <div className="flex justify-end gap-3 pt-2">
                <button onClick={close} className="rounded-lg px-4 py-2 text-sm transition-opacity hover:opacity-80" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
                  Cancel
                </button>
                <Button onClick={() => confirmMutation.mutate()} disabled={confirmMutation.isPending}>
                  {confirmMutation.isPending ? <Loader2 size={14} className="animate-spin" /> : <CheckCircle size={14} />}
                  {confirmMutation.isPending ? 'Saving…' : 'Confirm & Update Trade Package'}
                </Button>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
