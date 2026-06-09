'use client';

import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckSquare, Download, FileText, Square, X } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '@/lib/api';

type TemplateItem = {
  id: number;
  name: string;
  category: string;
  template_type: string | null;
  variables?: string[];
};

type TradePackageInfo = {
  id: number;
  name: string;
  package_code?: string | null;
  package_reference?: string | null;
  contractor_name?: string | null;
  description?: string | null;
};

type ProjectInfo = {
  organization?: { name?: string | null } | null;
  client?: { name?: string | null } | null;
  name?: string | null;
  code?: string | null;
  address?: string | null;
  metadata?: Record<string, unknown> | null;
};

type GeneratedFileItem = {
  name: string;
  document_type: string;
  file_upload_id?: number;
};

type GenerateResult = {
  generation_type: string;
  message: string;
  filename?: string;
  generated_files?: GeneratedFileItem[];
  resolved_count: number;
  unresolved_count: number;
  file_upload?: { id: number; original_name: string };
};

type ApiError = {
  response?: { data?: { message?: string } };
};

type FormState = Record<string, string>;

const SEPARATE_DOCUMENT_TYPES: Array<{ key: string; label: string }> = [
  { key: 'procurement_summary',   label: 'Procurement Summary' },
  { key: 'tender_enquiry_letter', label: 'Tender Enquiry Letter' },
  { key: 'schedule_of_documents', label: 'Schedule of Documents' },
  { key: 'subcontract_draft',     label: 'Subcontract Draft' },
];

const FIELD_SECTIONS: Array<{
  title: string;
  fields: Array<{ key: string; label: string; type?: 'text' | 'email' | 'textarea' }>;
}> = [
  {
    title: 'Project Details',
    fields: [
      { key: 'company_name', label: 'Company Name' },
      { key: 'project_name', label: 'Project Name' },
      { key: 'project_reference', label: 'Project Reference' },
      { key: 'site_address', label: 'Site Address', type: 'textarea' },
      { key: 'employer_name', label: 'Employer Name' },
      { key: 'architect_name', label: 'Architect Name' },
      { key: 'qs_name', label: 'QS Name' },
      { key: 'principal_designer', label: 'Principal Designer' },
    ],
  },
  {
    title: 'Trade Package Details',
    fields: [
      { key: 'trade_package', label: 'Trade Package' },
      { key: 'package_reference', label: 'Package Reference' },
      { key: 'pkg_code', label: 'Package Code' },
      { key: 'package_scope', label: 'Package Scope', type: 'textarea' },
    ],
  },
  {
    title: 'Contractor Details',
    fields: [
      { key: 'contractor_name', label: 'Contractor Name' },
      { key: 'contractor_legal_name', label: 'Contractor Legal Name' },
      { key: 'contractor_company_number', label: 'Company Number' },
      { key: 'contractor_registered_address', label: 'Registered Address', type: 'textarea' },
      { key: 'contractor_contact_name', label: 'Contact Name' },
      { key: 'contractor_email', label: 'Email', type: 'email' },
    ],
  },
  {
    title: 'Commercial Details',
    fields: [
      { key: 'contract_sum', label: 'Contract Sum' },
      { key: 'contract_sum_words', label: 'Contract Sum in Words', type: 'textarea' },
      { key: 'start_date', label: 'Start Date' },
      { key: 'completion_date', label: 'Completion Date' },
      { key: 'contract_duration', label: 'Contract Duration' },
      { key: 'retention_percentage', label: 'Retention Percentage' },
      { key: 'retention_half_percentage', label: 'Retention Half Percentage' },
      { key: 'ld_rate', label: 'LD Rate' },
      { key: 'rectification_period', label: 'Rectification Period' },
      { key: 'valuation_day', label: 'Valuation Day' },
      { key: 'document_date', label: 'Document Date' },
    ],
  },
  {
    title: 'Optional References',
    fields: [
      { key: 'drawing_schedule_ref', label: 'Drawing Schedule Ref' },
      { key: 'specification_ref', label: 'Specification Ref' },
      { key: 'pricing_doc_ref', label: 'Pricing Doc Ref' },
      { key: 'prelims_ref', label: 'Prelims Ref' },
    ],
  },
];

function InputField({
  label, name, type = 'text', value, onChange,
}: {
  label: string; name: string; type?: 'text' | 'email' | 'textarea'; value: string; onChange: (name: string, value: string) => void;
}) {
  const className = 'w-full rounded-lg px-3 py-2 text-sm outline-none';
  const style = {
    backgroundColor: 'var(--bg-base)',
    border: '1px solid var(--border)',
    color: 'var(--text-primary)',
  } as const;

  return (
    <div>
      <label className="mb-1 block text-xs" style={{ color: 'var(--text-muted)' }}>{label}</label>
      {type === 'textarea' ? (
        <textarea name={name} value={value} onChange={(e) => onChange(name, e.target.value)}
          rows={3} className={`${className} resize-none`} style={style} />
      ) : (
        <input name={name} type={type} value={value} onChange={(e) => onChange(name, e.target.value)}
          className={className} style={style} />
      )}
    </div>
  );
}

export default function GeneratePackageModal({
  projectId, tradePackage, onClose, onViewInPackage,
}: {
  projectId: string;
  tradePackage: TradePackageInfo;
  onClose: () => void;
  onViewInPackage: () => void;
}) {
  const queryClient = useQueryClient();
  const [generationType, setGenerationType] = useState<'complete_package' | 'separate_documents'>('complete_package');
  const [selectedTemplateId, setSelectedTemplateId] = useState<number | null>(null);
  const [selectedDocTypes, setSelectedDocTypes] = useState<Set<string>>(
    new Set(SEPARATE_DOCUMENT_TYPES.map(d => d.key))
  );
  const [result, setResult] = useState<GenerateResult | null>(null);
  const [overrides, setOverrides] = useState<FormState>({});

  const { data: templatesResponse, isLoading: templatesLoading } = useQuery({
    queryKey: ['document-templates', 'subcontract'],
    queryFn: () => api.get('/templates', { params: { category: 'subcontract' } }).then((r) => r.data),
  });

  const { data: projectResponse } = useQuery({
    queryKey: ['project', projectId, 'generate-package'],
    queryFn: () => api.get(`/projects/${projectId}`).then((r) => r.data),
  });

  const allTemplates = useMemo<TemplateItem[]>(() => templatesResponse?.data ?? [], [templatesResponse]);
  const masterTemplates = useMemo(
    () => allTemplates.filter(t => t.template_type === 'master_package'),
    [allTemplates]
  );
  const project: ProjectInfo | null = projectResponse ?? null;

  const preferredMasterId = useMemo(() => {
    if (!masterTemplates.length) return allTemplates[0]?.id ?? null;
    return masterTemplates[0].id;
  }, [masterTemplates, allTemplates]);

  const activeMasterTemplateId = selectedTemplateId ?? preferredMasterId;

  const prefilledForm = useMemo<FormState>(() => {
    const metadata = (project?.metadata ?? {}) as Record<string, string | undefined>;
    return {
      company_name:      project?.organization?.name ?? '',
      project_name:      project?.name ?? '',
      project_reference: project?.code ?? '',
      site_address:      project?.address ?? '',
      employer_name:     project?.client?.name ?? '',
      architect_name:    metadata.architect_name ?? '',
      qs_name:           metadata.qs_name ?? '',
      principal_designer: metadata.principal_designer ?? '',
      trade_package:     tradePackage.name ?? '',
      package_reference: tradePackage.package_reference ?? '',
      pkg_code:          tradePackage.package_code ?? '',
      package_scope:     tradePackage.description ?? '',
      contractor_name:   tradePackage.contractor_name ?? '',
    };
  }, [project, tradePackage]);

  const form = useMemo<FormState>(() => ({ ...prefilledForm, ...overrides }), [overrides, prefilledForm]);

  const allSelected = selectedDocTypes.size === SEPARATE_DOCUMENT_TYPES.length;

  function toggleDocType(key: string) {
    setSelectedDocTypes(prev => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key); else next.add(key);
      return next;
    });
  }

  function toggleAll() {
    if (allSelected) {
      setSelectedDocTypes(new Set());
    } else {
      setSelectedDocTypes(new Set(SEPARATE_DOCUMENT_TYPES.map(d => d.key)));
    }
  }

  const generateMutation = useMutation({
    mutationFn: async () => {
      const payload: Record<string, unknown> = Object.fromEntries(
        Object.entries(form).filter(([, v]) => v.trim() !== '')
      );

      if (generationType === 'complete_package') {
        if (!activeMasterTemplateId) throw new Error('Please select a master package template.');
        payload.generation_type = 'master_package';
        payload.template_id = String(activeMasterTemplateId);
      } else {
        if (selectedDocTypes.size === 0) throw new Error('Please select at least one document type.');
        payload.generation_type = 'separate_documents';
        payload.selected_document_types = Array.from(selectedDocTypes);
      }

      const response = await api.post(`/trade-packages/${tradePackage.id}/generate-package`, payload);
      return response.data as GenerateResult;
    },
    onSuccess: (data) => {
      setResult(data);
      queryClient.invalidateQueries({ queryKey: ['project-module-files', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-doc-explorer', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-documents', projectId] });
      queryClient.invalidateQueries({ queryKey: ['admin-doc-explorer-files'] });
      toast.success(data.message || 'Document generated successfully');
    },
    onError: (error: ApiError) => {
      toast.error(error?.response?.data?.message || 'Failed to generate package');
    },
  });

  const handleDownload = async (fileId?: number, fileName?: string) => {
    const id = fileId ?? result?.file_upload?.id;
    const name = fileName ?? result?.file_upload?.original_name ?? result?.filename ?? 'generated.docx';
    if (!id) return;

    const response = await api.get(`/file-uploads/${id}/download`, { responseType: 'blob' });
    const url = window.URL.createObjectURL(new Blob([response.data]));
    const a = document.createElement('a');
    a.href = url; a.download = name; a.click();
    window.URL.revokeObjectURL(url);
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="max-h-[92vh] w-full max-w-5xl overflow-y-auto rounded-2xl shadow-xl"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Generate Package</h2>
            <p className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>
              Only completed fields will replace placeholders. Empty fields will remain editable in the generated Word document.
            </p>
          </div>
          <button onClick={onClose} aria-label="Close">
            <X size={18} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        {result ? (
          <div className="space-y-5 p-5">
            <div className="rounded-xl p-4" style={{ backgroundColor: 'rgba(34,197,94,0.08)', border: '1px solid rgba(34,197,94,0.22)' }}>
              <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{result.message}</p>
              <div className="mt-3 flex flex-wrap gap-3 text-xs" style={{ color: 'var(--text-muted)' }}>
                <span>Resolved placeholders: {result.resolved_count}</span>
                <span>Unresolved placeholders: {result.unresolved_count}</span>
              </div>
              {result.generated_files && result.generated_files.length > 0 && (
                <div className="mt-3 space-y-2">
                  {result.generated_files.map((f, i) => (
                    <div key={i} className="flex items-center justify-between gap-2 rounded-lg px-3 py-2"
                      style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
                      <div>
                        <p className="text-xs font-medium" style={{ color: 'var(--text-primary)' }}>{f.name}</p>
                        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{f.document_type}</p>
                      </div>
                      {f.file_upload_id && (
                        <button
                          onClick={() => handleDownload(f.file_upload_id, f.name)}
                          className="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium"
                          style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
                        >
                          <Download size={12} /> Download
                        </button>
                      )}
                    </div>
                  ))}
                </div>
              )}
            </div>
            <div className="flex flex-wrap justify-end gap-3">
              <button type="button" onClick={onViewInPackage}
                className="rounded-lg px-4 py-2 text-sm"
                style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-primary)' }}>
                View in Package
              </button>
              <button type="button"
                onClick={() => { setResult(null); setOverrides({}); }}
                className="rounded-lg px-4 py-2 text-sm"
                style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-primary)' }}>
                Generate Another
              </button>
              {result.file_upload && (
                <button type="button" onClick={() => handleDownload()}
                  className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium"
                  style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
                  <Download size={14} /> Download
                </button>
              )}
            </div>
          </div>
        ) : (
          <form onSubmit={(e) => { e.preventDefault(); generateMutation.mutate(); }}
            className="space-y-6 p-5">

            {/* Generation Type */}
            <div>
              <label className="mb-2 block text-xs font-medium" style={{ color: 'var(--text-muted)' }}>Generation Type</label>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                {([
                  {
                    value: 'complete_package',
                    title: 'Complete Package',
                    body: 'Generate one combined DOCX using the master package template.',
                  },
                  {
                    value: 'separate_documents',
                    title: 'Separate Documents',
                    body: 'Generate individual DOCX files for selected subcontract documents.',
                  },
                ] as const).map((opt) => (
                  <button
                    key={opt.value}
                    type="button"
                    onClick={() => setGenerationType(opt.value)}
                    className="rounded-xl p-4 text-left transition-colors"
                    style={{
                      backgroundColor: generationType === opt.value ? 'rgba(185,149,102,0.1)' : 'var(--bg-elevated)',
                      border: `1px solid ${generationType === opt.value ? 'var(--gold)' : 'var(--border)'}`,
                    }}
                  >
                    <div className="flex items-center gap-2 mb-1">
                      <div className="w-3.5 h-3.5 rounded-full flex items-center justify-center flex-shrink-0"
                        style={{ border: `2px solid ${generationType === opt.value ? 'var(--gold)' : 'var(--border)'}` }}>
                        {generationType === opt.value && (
                          <div className="w-1.5 h-1.5 rounded-full" style={{ backgroundColor: 'var(--gold)' }} />
                        )}
                      </div>
                      <span className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{opt.title}</span>
                    </div>
                    <p className="text-xs pl-5" style={{ color: 'var(--text-muted)' }}>{opt.body}</p>
                  </button>
                ))}
              </div>
            </div>

            {/* Complete Package: template picker */}
            {generationType === 'complete_package' && (
              <div>
                <label className="mb-1 block text-xs" style={{ color: 'var(--text-muted)' }}>Template</label>
                <select
                  value={activeMasterTemplateId ?? ''}
                  onChange={(e) => setSelectedTemplateId(Number(e.target.value))}
                  className="w-full rounded-lg px-3 py-2 text-sm outline-none"
                  style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                  disabled={templatesLoading}
                >
                  <option value="" disabled>
                    {templatesLoading ? 'Loading templates…' : masterTemplates.length === 0 ? 'No master package templates found' : 'Select template'}
                  </option>
                  {(masterTemplates.length > 0 ? masterTemplates : allTemplates).map((t) => (
                    <option key={t.id} value={t.id}>{t.name}</option>
                  ))}
                </select>
                {masterTemplates.length === 0 && !templatesLoading && (
                  <p className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>
                    No master package templates found. Upload a template with type "Master Package" first.
                  </p>
                )}
              </div>
            )}

            {/* Separate Documents: checkboxes */}
            {generationType === 'separate_documents' && (
              <div>
                <label className="mb-2 block text-xs font-medium" style={{ color: 'var(--text-muted)' }}>Documents to Generate</label>
                <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
                  <button
                    type="button"
                    onClick={toggleAll}
                    className="flex w-full items-center gap-3 px-4 py-3 text-sm transition-colors hover:bg-[var(--bg-hover)]"
                    style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}
                  >
                    {allSelected
                      ? <CheckSquare size={16} style={{ color: 'var(--gold)' }} />
                      : <Square size={16} style={{ color: 'var(--text-muted)' }} />
                    }
                    <span className="font-medium" style={{ color: 'var(--text-primary)' }}>Select All</span>
                  </button>
                  {SEPARATE_DOCUMENT_TYPES.map((doc) => (
                    <button
                      key={doc.key}
                      type="button"
                      onClick={() => toggleDocType(doc.key)}
                      className="flex w-full items-center gap-3 px-4 py-3 text-sm transition-colors hover:bg-[var(--bg-hover)]"
                      style={{ backgroundColor: 'var(--bg-surface)', borderBottom: '1px solid var(--border)' }}
                    >
                      {selectedDocTypes.has(doc.key)
                        ? <CheckSquare size={16} style={{ color: 'var(--gold)' }} />
                        : <Square size={16} style={{ color: 'var(--text-muted)' }} />
                      }
                      <span style={{ color: 'var(--text-primary)' }}>{doc.label}</span>
                    </button>
                  ))}
                </div>
                <p className="mt-2 text-xs" style={{ color: 'var(--text-muted)' }}>
                  Each selected document will use its matching template automatically. {selectedDocTypes.size} selected.
                </p>
              </div>
            )}

            {/* Placeholder fields */}
            {FIELD_SECTIONS.map((section) => (
              <section key={section.title} className="space-y-3">
                <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{section.title}</h3>
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                  {section.fields.map((field) => (
                    <div key={field.key} className={field.type === 'textarea' ? 'md:col-span-2' : ''}>
                      <InputField
                        label={field.label}
                        name={field.key}
                        type={field.type}
                        value={form[field.key] ?? ''}
                        onChange={(name, value) => setOverrides((cur) => ({ ...cur, [name]: value }))}
                      />
                    </div>
                  ))}
                </div>
              </section>
            ))}

            <div className="flex justify-end gap-3 pt-2">
              <button type="button" onClick={onClose}
                className="rounded-lg px-4 py-2 text-sm"
                style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
                Cancel
              </button>
              <button
                type="submit"
                disabled={
                  generateMutation.isPending ||
                  (generationType === 'complete_package' && !activeMasterTemplateId) ||
                  (generationType === 'separate_documents' && selectedDocTypes.size === 0)
                }
                className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: generateMutation.isPending ? 0.7 : 1 }}
              >
                <FileText size={14} />
                {generateMutation.isPending ? 'Generating…' : 'Generate Package'}
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
}
