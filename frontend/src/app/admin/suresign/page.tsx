'use client';

import { useState, useEffect, useRef } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { useAuthStore } from '@/store/authStore';
import {
  Gem, Check, Save, RefreshCw, FileText, Mail, ImageIcon,
  X, Upload, Palette, Globe, FileUp, Download, Send, Eye, EyeOff, Wrench,
} from 'lucide-react';
import Select from '@/components/ui/Select';
import PlatformPageHero from '@/components/admin/PlatformPageHero';
import FeatureAvailabilityManager from '@/components/admin/FeatureAvailabilityManager';

// ─── Types ────────────────────────────────────────────────────────────────────
interface PlatformSettings {
  logo_url:               string | null;
  favicon_url:            string | null;
  letterhead_header_url:  string | null;
  letterhead_footer_url:  string | null;
  letterhead_pdf_url:     string | null;
  email_header_url:       string | null;
  email_footer_url:       string | null;
  email_sender_email:     string;
  email_sender_name:      string;
  email_subject_line:     string;
  email_body_template:    string;
  email_reply_to:         string;
  admin_email:            string;
  brevo_api_key:          string;
  has_brevo_key:          boolean;
  currency:               string;
  currency_symbol:        string;
  date_format:            string;
  timezone:               string;
  hidden_pages:           string[];
}

// ─── Constants ────────────────────────────────────────────────────────────────
const CURRENCIES = [
  { code: 'GBP', symbol: '\u00a3', label: 'British Pound (\u00a3)' },
  { code: 'USD', symbol: '$',       label: 'US Dollar ($)' },
  { code: 'EUR', symbol: '\u20ac', label: 'Euro (\u20ac)' },
  { code: 'AUD', symbol: 'A$',      label: 'Australian Dollar (A$)' },
  { code: 'CAD', symbol: 'C$',      label: 'Canadian Dollar (C$)' },
  { code: 'NZD', symbol: 'NZ$',     label: 'New Zealand Dollar (NZ$)' },
  { code: 'SGD', symbol: 'S$',      label: 'Singapore Dollar (S$)' },
  { code: 'AED', symbol: '\u062f.\u0625', label: 'UAE Dirham' },
  { code: 'ZAR', symbol: 'R',       label: 'South African Rand (R)' },
];

const DATE_FORMATS = [
  { value: 'DD/MM/YYYY', label: 'DD/MM/YYYY  \u2014 31/01/2026' },
  { value: 'MM/DD/YYYY', label: 'MM/DD/YYYY  \u2014 01/31/2026' },
  { value: 'YYYY-MM-DD', label: 'YYYY-MM-DD  \u2014 2026-01-31' },
  { value: 'D MMM YYYY', label: 'D MMM YYYY  \u2014 31 Jan 2026' },
];

const TABS = [
  { id: 'branding',  label: 'Branding',          icon: Palette,  color: '#b99566', superAdminOnly: false },
  { id: 'document',  label: 'Document Settings',  icon: FileText, color: '#3b82f6', superAdminOnly: false },
  { id: 'email',     label: 'Email Settings',     icon: Mail,     color: '#8b5cf6', superAdminOnly: false },
  { id: 'site',      label: 'Site Settings',      icon: Globe,    color: '#10b981', superAdminOnly: false },
  // Super Admin ONLY — matches the Feature Availability management API's
  // own `role:Super Admin` authorization exactly (Admin is deliberately
  // excluded, unlike every other tab here). Filtered out of the rendered
  // tab bar below when the current user isn't Super Admin.
  { id: 'feature-availability', label: 'Feature Availability', icon: Wrench, color: '#eab308', superAdminOnly: true },
] as const;

type TabId = typeof TABS[number]['id'];

// ─── Small helpers ────────────────────────────────────────────────────────────
function Field({ label, value, onChange, placeholder, type = 'text', hint }: {
  label: string; value: string; onChange: (v: string) => void;
  placeholder?: string; type?: string; hint?: string;
}) {
  return (
    <div>
      <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>{label}</label>
      <input
        type={type} value={value} onChange={e => onChange(e.target.value)}
        placeholder={placeholder}
        className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
        style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
      />
      {hint && <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{hint}</p>}
    </div>
  );
}

function SelectField({ label, value, onChange, options, hint }: {
  label: string; value: string; onChange: (v: string) => void;
  options: { value: string; label: string }[]; hint?: string;
}) {
  return (
    <div>
      <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>{label}</label>
      <Select
        value={value} onChange={e => onChange(e.target.value)}
        className="w-full"
      >
        {options.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
      </Select>
      {hint && <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{hint}</p>}
    </div>
  );
}

function Divider() {
  return <div style={{ borderTop: '1px solid var(--border)' }} />;
}

function SubLabel({ children }: { children: React.ReactNode }) {
  return <p className="text-xs font-semibold" style={{ color: 'var(--text-secondary)' }}>{children}</p>;
}

// ─── Save button ──────────────────────────────────────────────────────────────
function SaveBtn({ onClick, pending, saved }: { onClick: () => void; pending: boolean; saved: boolean }) {
  return (
    <button
      onClick={onClick} disabled={pending}
      className="flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-medium transition-all disabled:opacity-60 active:scale-[0.98]"
      style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
    >
      {pending ? <RefreshCw size={14} className="animate-spin" /> : saved ? <Check size={14} /> : <Save size={14} />}
      {saved ? 'Saved!' : pending ? 'Saving\u2026' : 'Save Settings'}
    </button>
  );
}

// ─── Upload tile ──────────────────────────────────────────────────────────────
function UploadTile({ label, hint, accept, currentUrl, onUpload, onRemove, uploading, isPdf }: {
  label: string; hint: string; accept: string; currentUrl: string | null;
  onUpload: (file: File) => void; onRemove?: () => void; uploading: boolean; isPdf?: boolean;
}) {
  const ref = useRef<HTMLInputElement>(null);
  const [preview, setPreview] = useState<string | null>(currentUrl);
  const [fileName, setFileName] = useState<string | null>(null);

  useEffect(() => { setPreview(currentUrl); }, [currentUrl]);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    if (file.type === 'application/pdf') { setFileName(file.name); setPreview(null); }
    else { setPreview(URL.createObjectURL(file)); setFileName(null); }
    onUpload(file);
    e.target.value = '';
  };

  const has = !!(preview || fileName);

  return (
    <div>
      <p className="text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>{label}</p>
      <p className="text-xs mb-2.5" style={{ color: 'var(--text-muted)' }}>{hint}</p>
      <div
        onClick={() => !uploading && ref.current?.click()}
        className="group relative rounded-xl border-2 border-dashed flex items-center justify-center transition-all cursor-pointer overflow-hidden w-full h-28"
        style={{ borderColor: has ? 'transparent' : 'var(--border)', backgroundColor: 'var(--bg-elevated)' }}
        onMouseEnter={e => { if (!has) (e.currentTarget as HTMLElement).style.borderColor = 'var(--gold)'; }}
        onMouseLeave={e => { if (!has) (e.currentTarget as HTMLElement).style.borderColor = 'var(--border)'; }}
      >
        {preview ? (
          <>
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={preview} alt={label} className="w-full h-full object-contain" />
            <div className="absolute inset-0 flex flex-col items-center justify-center gap-1.5 opacity-0 group-hover:opacity-100 transition-opacity" style={{ backgroundColor: 'rgba(0,0,0,0.55)' }}>
              <Upload size={15} className="text-white" /><span className="text-xs text-white font-medium">Replace</span>
            </div>
          </>
        ) : fileName ? (
          <>
            <div className="flex flex-col items-center gap-1.5 p-4">
              <div className="w-8 h-8 rounded-lg flex items-center justify-center" style={{ backgroundColor: 'rgba(59,130,246,0.12)' }}>
                <FileUp size={15} style={{ color: '#3b82f6' }} />
              </div>
              <span className="text-xs font-medium truncate max-w-[180px]" style={{ color: 'var(--text-primary)' }}>{fileName}</span>
            </div>
            <div className="absolute inset-0 flex flex-col items-center justify-center gap-1.5 opacity-0 group-hover:opacity-100 transition-opacity" style={{ backgroundColor: 'rgba(0,0,0,0.55)' }}>
              <Upload size={15} className="text-white" /><span className="text-xs text-white font-medium">Replace</span>
            </div>
          </>
        ) : (
          <div className="flex flex-col items-center gap-2 p-4">
            <div className="w-8 h-8 rounded-lg flex items-center justify-center" style={{ backgroundColor: 'var(--gold-15)' }}>
              {isPdf ? <FileUp size={15} style={{ color: 'var(--gold)' }} /> : <ImageIcon size={15} style={{ color: 'var(--gold)' }} />}
            </div>
            <span className="text-xs text-center" style={{ color: 'var(--text-muted)' }}>{uploading ? 'Uploading\u2026' : 'Click to upload'}</span>
          </div>
        )}
        {uploading && (
          <div className="absolute inset-0 flex items-center justify-center" style={{ backgroundColor: 'rgba(0,0,0,0.45)' }}>
            <RefreshCw size={18} className="text-white animate-spin" />
          </div>
        )}
      </div>
      {has && !uploading && (
        <button onClick={() => { setPreview(null); setFileName(null); onRemove?.(); }} className="mt-1 flex items-center gap-1 text-xs hover:text-red-400" style={{ color: 'var(--text-muted)' }}>
          <X size={11} /> Remove
        </button>
      )}
      <input ref={ref} type="file" accept={accept} className="hidden" onChange={handleChange} />
    </div>
  );
}

// ─── Page ─────────────────────────────────────────────────────────────────────
export default function AdminSureSignPage() {
  const qc = useQueryClient();
  const { user } = useAuthStore();
  const isSuperAdmin = user?.roles?.includes('Super Admin');
  const [activeTab, setActiveTab] = useState<TabId>(() => {
    if (typeof window === 'undefined') return 'branding';
    const stored = localStorage.getItem('suresign_settings_tab') as TabId;
    return TABS.some(t => t.id === stored) ? stored : 'branding';
  });

  const changeTab = (tab: TabId) => {
    setActiveTab(tab);
    localStorage.setItem('suresign_settings_tab', tab);
  };
  const [savedTab, setSavedTab] = useState<TabId | null>(null);
  const [uploading, setUploading] = useState<Record<string, boolean>>({});
  const [showBrevoKey, setShowBrevoKey] = useState(false);
  const emailBodyRef = useRef<HTMLTextAreaElement>(null);

  const insertPlaceholder = (ph: string) => {
    const ta = emailBodyRef.current;
    if (!ta) return;
    const start = ta.selectionStart ?? ta.value.length;
    const end   = ta.selectionEnd   ?? ta.value.length;
    const next  = ta.value.substring(0, start) + ph + ta.value.substring(end);
    setEmailForm(p => ({ ...p, email_body_template: next }));
    requestAnimationFrame(() => { ta.focus(); ta.setSelectionRange(start + ph.length, start + ph.length); });
  };
  const [testEmailAddr, setTestEmailAddr] = useState('');
  const [testEmailStatus, setTestEmailStatus] = useState<'idle' | 'sending' | 'ok' | 'err'>('idle');
  const [testEmailMsg, setTestEmailMsg] = useState('');
  const [testPdfLoading, setTestPdfLoading] = useState(false);
  const [testPdfError, setTestPdfError] = useState('');

  const { data, isLoading } = useQuery<PlatformSettings>({
    queryKey: ['admin-suresign-settings'],
    queryFn: () =>
      api.get('/admin/suresign-settings').then(r => r.data?.data ?? r.data).catch(() => ({
        logo_url: null, favicon_url: null, letterhead_header_url: null, letterhead_footer_url: null,
        letterhead_pdf_url: null, email_header_url: null, email_footer_url: null,
        email_subject_line: '', email_body_template: '', email_reply_to: '',
        email_sender_email: '', email_sender_name: 'SureSign Contracts', admin_email: '',
        brevo_api_key: '', has_brevo_key: false,
        currency: 'GBP', currency_symbol: '\u00a3', date_format: 'DD/MM/YYYY', timezone: 'Europe/London',
        hidden_pages: [],
      })),
    staleTime: 5 * 60 * 1000,
  });

  // ── Per-section form state ────────────────────────────────────────────────
  const [emailForm, setEmailForm] = useState({
    email_sender_email: '', email_sender_name: 'SureSign Contracts',
    email_reply_to: '', admin_email: '', email_subject_line: '', email_body_template: '', brevo_api_key: '',
  });
  const [siteForm, setSiteForm] = useState({
    currency: 'GBP', currency_symbol: '\u00a3', date_format: 'DD/MM/YYYY', timezone: 'Europe/London',    hidden_pages: [] as string[],  });

  useEffect(() => {
    if (!data) return;
    setEmailForm({
      email_sender_email:  data.email_sender_email  ?? '',
      email_sender_name:   data.email_sender_name   ?? 'SureSign Contracts',
      email_reply_to:      data.email_reply_to      ?? '',
      admin_email:         data.admin_email         ?? '',
      email_subject_line:  data.email_subject_line  ?? '',
      email_body_template: data.email_body_template ?? '',
      brevo_api_key:       data.brevo_api_key        ?? '',
    });
    setSiteForm({
      currency:        data.currency        ?? 'GBP',
      currency_symbol: data.currency_symbol ?? '\u00a3',
      date_format:     data.date_format     ?? 'DD/MM/YYYY',
      timezone:        data.timezone        ?? 'Europe/London',      hidden_pages:    data.hidden_pages    ?? [],    });
  }, [data]);

  const handleCurrencyChange = (code: string) => {
    const found = CURRENCIES.find(c => c.code === code);
    setSiteForm(p => ({ ...p, currency: code, currency_symbol: found?.symbol ?? '' }));
  };

  const markSaved = (tab: TabId) => {
    setSavedTab(tab);
    setTimeout(() => setSavedTab(null), 2500);
  };

  // ── Mutations ─────────────────────────────────────────────────────────────
  const emailMutation = useMutation({
    mutationFn: (payload: typeof emailForm) => api.put('/admin/suresign-settings/email', payload),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['admin-suresign-settings'] }); markSaved('email'); },
  });

  const siteMutation = useMutation({
    mutationFn: (payload: typeof siteForm) => api.put('/admin/suresign-settings/site', payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin-suresign-settings'] });
      qc.invalidateQueries({ queryKey: ['site-settings'] });
      markSaved('site');
    },
  });

  // ── File upload helper ────────────────────────────────────────────────────
  const uploadFile = async (field: string, endpoint: string, paramName: string, file: File, tab: TabId) => {
    setUploading(p => ({ ...p, [field]: true }));
    try {
      const fd = new FormData();
      fd.append(paramName, file);
      await api.post(endpoint, fd, { headers: { 'Content-Type': 'multipart/form-data' } });
      qc.invalidateQueries({ queryKey: ['admin-suresign-settings'] });
      qc.invalidateQueries({ queryKey: ['site-settings'] });
      markSaved(tab);
    } finally {
      setUploading(p => ({ ...p, [field]: false }));
    }
  };

  // ── File remove helper ────────────────────────────────────────────────────
  const removeFile = async (endpoint: string, tab: TabId) => {
    try {
      await api.delete(endpoint);
      qc.invalidateQueries({ queryKey: ['admin-suresign-settings'] });
      markSaved(tab);
      qc.invalidateQueries({ queryKey: ['site-settings'] });
    } catch { /* silently ignore — local state already cleared */ }
  };

  // ── Test PDF ──────────────────────────────────────────────────────────────
  const handleTestPdf = async () => {
    setTestPdfLoading(true);
    setTestPdfError('');
    try {
      const res = await api.post('/admin/suresign-settings/test-pdf', {}, { responseType: 'blob' });
      const blob = new Blob([res.data], { type: 'application/pdf' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = 'suresign-letterhead-test.pdf'; a.click();
      URL.revokeObjectURL(url);
    } catch (e: any) {
      // When responseType is 'blob', error body is also a Blob — read it as text
      let msg = 'Failed to generate PDF. Check the server logs for details.';
      try {
        const errBlob: Blob = e?.response?.data;
        if (errBlob instanceof Blob) {
          const text = await errBlob.text();
          const json = JSON.parse(text);
          msg = json.message ?? msg;
        } else if (e?.response?.data?.message) {
          msg = e.response.data.message;
        }
      } catch { /* keep default msg */ }
      setTestPdfError(msg);
    } finally {
      setTestPdfLoading(false);
    }
  };

  // ── Test Email ────────────────────────────────────────────────────────────
  const handleTestEmail = async () => {
    if (!testEmailAddr) return;
    setTestEmailStatus('sending');
    try {
      const res = await api.post('/admin/suresign-settings/test-email', { to: testEmailAddr });
      setTestEmailStatus('ok');
      setTestEmailMsg(res.data?.message ?? 'Sent!');
    } catch (e: any) {
      setTestEmailStatus('err');
      // email errors come back as JSON normally
      const msg = e?.response?.data?.message ?? e?.message ?? 'Failed to send.';
      setTestEmailMsg(msg);
    }
    setTimeout(() => setTestEmailStatus('idle'), 12000);
  };

  // ── Skeleton ──────────────────────────────────────────────────────────────
  if (isLoading) return (
    <div className="p-6 max-w-3xl mx-auto space-y-4">
      <div className="h-14 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
      <div className="h-12 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
      {[...Array(4)].map((_, i) => (
        <div key={i} className="h-16 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
      ))}
    </div>
  );

  const activeTabMeta = TABS.find(t => t.id === activeTab)!;
  const configuredAssets = [
    data?.logo_url,
    data?.favicon_url,
    data?.letterhead_header_url,
    data?.letterhead_footer_url,
    data?.letterhead_pdf_url,
    data?.email_header_url,
    data?.email_footer_url,
  ].filter(Boolean).length;

  return (
    <div className="mx-auto max-w-7xl space-y-7 p-4 pb-16 sm:p-6 lg:p-8">

      <PlatformPageHero
        eyebrow="System identity"
        title="SureSign Settings"
        description="Control the platform identity, document presentation, email delivery and regional defaults."
        metrics={[
          { label: 'Settings areas', value: TABS.length, detail: 'platform controls', icon: Gem },
          { label: 'Brand assets', value: configuredAssets, detail: 'files configured', icon: ImageIcon },
          { label: 'Email delivery', value: data?.email_sender_email ? 'Ready' : 'Setup', detail: data?.email_sender_email || 'sender not configured', icon: Mail },
        ]}
      />

      {/* ── Tab bar ── */}
      <div className="flex gap-1 p-1 rounded-xl" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        {TABS.filter(tab => !tab.superAdminOnly || isSuperAdmin).map(tab => {
          const Icon = tab.icon;
          const active = activeTab === tab.id;
          return (
            <button
              key={tab.id}
              onClick={() => changeTab(tab.id)}
              className="flex-1 flex items-center justify-center gap-2 px-3 py-2.5 rounded-lg text-xs font-medium transition-all"
              style={{
                backgroundColor: active ? 'var(--bg-elevated)' : 'transparent',
                color: active ? tab.color : 'var(--text-muted)',
                border: active ? '1px solid var(--border)' : '1px solid transparent',
              }}
            >
              <Icon size={14} />
              <span className="hidden sm:inline">{tab.label}</span>
            </button>
          );
        })}
      </div>

      {/* ── Section heading ── */}
      <div className="flex items-center gap-3">
        <div className="w-8 h-8 rounded-lg flex items-center justify-center" style={{ backgroundColor: activeTabMeta.color + '22' }}>
          <activeTabMeta.icon size={16} style={{ color: activeTabMeta.color }} />
        </div>
        <div>
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{activeTabMeta.label}</h2>
        </div>
      </div>

      {/* ══ BRANDING TAB ══ */}
      {activeTab === 'branding' && (
        <div className="rounded-2xl p-5 space-y-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <UploadTile
            label="SureSign Contracts logo"
            hint="Transparent background recommended · PNG or SVG · min 300 × 100 px"
            accept="image/png,image/svg+xml,image/jpeg,image/webp"
            currentUrl={data?.logo_url ?? null}
            uploading={!!uploading.logo}
            onUpload={f => uploadFile('logo', '/admin/suresign-settings/logo', 'logo', f, 'branding')}
            onRemove={() => removeFile('/admin/suresign-settings/logo', 'branding')}
          />
          <UploadTile
            label="Site favicon"
            hint="Browser tab icon · square PNG, SVG, or ICO · min 32 × 32 px"
            accept="image/png,image/svg+xml,image/x-icon,.ico"
            currentUrl={data?.favicon_url ?? null}
            uploading={!!uploading.favicon}
            onUpload={f => uploadFile('favicon', '/admin/suresign-settings/favicon', 'favicon', f, 'branding')}
            onRemove={() => removeFile('/admin/suresign-settings/favicon', 'branding')}
          />
          {savedTab === 'branding' && (
            <p className="flex items-center gap-1.5 text-xs" style={{ color: '#10b981' }}>
              <Check size={12} /> Branding saved successfully.
            </p>
          )}
        </div>
      )}

      {/* ══ DOCUMENT TAB ══ */}
      {activeTab === 'document' && (
        <>
          <div className="rounded-2xl p-5 space-y-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <div className="space-y-3">
              <SubLabel>Letterhead — Header &amp; Footer Images</SubLabel>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <UploadTile
                  label="Header Image"
                  hint="Top of every PDF · A4 width (2480 px) · max 130 px tall · PNG"
                  accept="image/png,image/jpeg,image/webp"
                  currentUrl={data?.letterhead_header_url ?? null}
                  uploading={!!uploading.lthdHeader}
                  onUpload={f => uploadFile('lthdHeader', '/admin/suresign-settings/letterhead-header', 'header', f, 'document')}
                  onRemove={() => removeFile('/admin/suresign-settings/letterhead-header', 'document')}
                />
                <UploadTile
                  label="Footer Image"
                  hint="Bottom of every PDF · A4 width (2480 px) · max 65 px tall · PNG"
                  accept="image/png,image/jpeg,image/webp"
                  currentUrl={data?.letterhead_footer_url ?? null}
                  uploading={!!uploading.lthdFooter}
                  onUpload={f => uploadFile('lthdFooter', '/admin/suresign-settings/letterhead-footer', 'footer', f, 'document')}
                  onRemove={() => removeFile('/admin/suresign-settings/letterhead-footer', 'document')}
                />
              </div>
            </div>

            <Divider />

            <div className="space-y-2">
              <SubLabel>
                Full Letterhead PDF{' '}
                <span className="font-normal ml-1" style={{ color: 'var(--text-muted)' }}>(optional — overrides images above)</span>
              </SubLabel>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                Upload a pre-designed PDF that already contains your header and footer. When present, it takes priority over the separate images.
              </p>
              <UploadTile
                label="Letterhead PDF"
                hint="Single-page A4 PDF with header & footer baked in · max 5 MB"
                accept="application/pdf"
                currentUrl={data?.letterhead_pdf_url ?? null}
                uploading={!!uploading.lthdPdf}
                isPdf
                onUpload={f => uploadFile('lthdPdf', '/admin/suresign-settings/letterhead-pdf', 'pdf', f, 'document')}
                onRemove={() => removeFile('/admin/suresign-settings/letterhead-pdf', 'document')}
              />
            </div>

            <div className="rounded-lg px-3 py-2.5 text-xs" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}>
              <span className="font-medium" style={{ color: 'var(--text-secondary)' }}>Tip: </span>
              Include company name, address, registration number and contact details in the letterhead artwork.
            </div>

            {savedTab === 'document' && (
              <p className="flex items-center gap-1.5 text-xs" style={{ color: '#10b981' }}>
                <Check size={12} /> Document settings saved.
              </p>
            )}
          </div>

          {/* Generate Test PDF */}
          <div className="rounded-2xl p-5 flex items-center justify-between" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <div>
              <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Generate Test PDF</p>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                Downloads a sample A4 document using your current letterhead settings.
              </p>
            </div>
            <button
              onClick={handleTestPdf}
              disabled={testPdfLoading}
              className="flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-medium transition-all disabled:opacity-60"
              style={{ backgroundColor: 'rgba(59,130,246,0.12)', color: '#3b82f6', border: '1px solid rgba(59,130,246,0.25)' }}
            >
              {testPdfLoading ? <RefreshCw size={13} className="animate-spin" /> : <Download size={13} />}
              {testPdfLoading ? 'Generating\u2026' : 'Generate Test PDF'}
            </button>
          </div>
          {testPdfError && (
            <p className="flex items-start gap-1.5 text-xs rounded-lg px-3 py-2.5" style={{ color: '#ef4444', backgroundColor: 'rgba(239,68,68,0.08)', border: '1px solid rgba(239,68,68,0.2)' }}>
              <X size={12} className="mt-0.5 flex-shrink-0" /> {testPdfError}
            </p>
          )}
        </>
      )}

      {/* ══ EMAIL TAB ══ */}
      {activeTab === 'email' && (
        <>
          <div className="rounded-2xl p-5 space-y-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <div className="space-y-3">
              <SubLabel>Email Header &amp; Footer Images</SubLabel>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <UploadTile
                  label="Header Image"
                  hint="Top of every email · 600 px wide · PNG"
                  accept="image/png,image/jpeg,image/webp"
                  currentUrl={data?.email_header_url ?? null}
                  uploading={!!uploading.emailHeader}
                  onUpload={f => uploadFile('emailHeader', '/admin/suresign-settings/email-header', 'header', f, 'email')}
                  onRemove={() => removeFile('/admin/suresign-settings/email-header', 'email')}
                />
                <UploadTile
                  label="Footer Image"
                  hint="Bottom of every email · 600 px wide · PNG"
                  accept="image/png,image/jpeg,image/webp"
                  currentUrl={data?.email_footer_url ?? null}
                  uploading={!!uploading.emailFooter}
                  onUpload={f => uploadFile('emailFooter', '/admin/suresign-settings/email-footer', 'footer', f, 'email')}
                  onRemove={() => removeFile('/admin/suresign-settings/email-footer', 'email')}
                />
              </div>
            </div>

            <Divider />

            {/* Brevo API Key */}
            <div>
              <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
                Brevo API Key
              </label>
              <div className="relative">
                <input
                  type={showBrevoKey ? 'text' : 'password'}
                  value={emailForm.brevo_api_key}
                  onChange={e => setEmailForm(p => ({ ...p, brevo_api_key: e.target.value }))}
                  placeholder={data?.has_brevo_key ? 'Key saved \u2014 enter new value to replace' : 'xkeysib-\u2026'}
                  className="w-full px-3 py-2.5 pr-10 rounded-lg text-sm outline-none font-mono"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                />
                <button
                  type="button"
                  onClick={() => setShowBrevoKey(v => !v)}
                  className="absolute right-3 top-1/2 -translate-y-1/2"
                  style={{ color: 'var(--text-muted)' }}
                >
                  {showBrevoKey ? <EyeOff size={14} /> : <Eye size={14} />}
                </button>
              </div>
              <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
                Get your API key from{' '}
                <a href="https://app.brevo.com/settings/keys/api" target="_blank" rel="noreferrer"
                   className="underline" style={{ color: 'var(--gold)' }}>
                  Brevo → Settings → API Keys
                </a>.
                {data?.has_brevo_key && <span className="ml-2" style={{ color: '#10b981' }}>✔ Key configured</span>}
              </p>
            </div>

            <Divider />

            <div className="grid grid-cols-2 gap-4">
              <Field
                label="Sender Email"
                value={emailForm.email_sender_email}
                onChange={v => setEmailForm(p => ({ ...p, email_sender_email: v }))}
                placeholder="jamescarlo.romero22@gmail.com"
                type="email"
                hint="Must be a verified sender in your Brevo account."
              />
              <Field
                label="Sender Name"
                value={emailForm.email_sender_name}
                onChange={v => setEmailForm(p => ({ ...p, email_sender_name: v }))}
                placeholder="SureSign Contracts"
                hint="Display name shown in the recipient's inbox."
              />
            </div>

            <Field
              label="Reply-To Email Address"
              value={emailForm.email_reply_to}
              onChange={v => setEmailForm(p => ({ ...p, email_reply_to: v }))}
              placeholder="noreply@suresign.io"
              type="email"
              hint="Recipients will reply to this address."
            />

            <Field
              label="Admin Email"
              value={emailForm.admin_email}
              onChange={v => setEmailForm(p => ({ ...p, admin_email: v }))}
              placeholder="tech@suresigncontracts.com"
              type="email"
              hint="Receives a copy of every notification email sent to a client organisation — separate from the sender/reply-to address above."
            />

            <Field
              label="Default Email Subject Line"
              value={emailForm.email_subject_line}
              onChange={v => setEmailForm(p => ({ ...p, email_subject_line: v }))}
              placeholder="You have a new document from SureSign Contracts"
              hint="Use {{document_name}} and {{company_name}} as merge fields."
            />

            <div>
              <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>Email Body Template</label>
              <textarea
                ref={emailBodyRef}
                value={emailForm.email_body_template}
                onChange={e => setEmailForm(p => ({ ...p, email_body_template: e.target.value }))}
                rows={8}
                placeholder={'Dear {{recipient_name}},\n\nPlease find attached "{{document_name}}" from {{company_name}}.\n\nKind regards,\nThe SureSign Contracts Team'}
                className="w-full px-3 py-2.5 rounded-lg text-sm outline-none resize-none font-mono"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
              />
              <div className="mt-2">
                <p className="text-xs mb-1.5" style={{ color: 'var(--text-muted)' }}>Click to insert merge field into body:</p>
                <div className="flex flex-wrap gap-1.5">
                  {['{{recipient_name}}', '{{document_name}}', '{{company_name}}', '{{sign_link}}'].map(ph => (
                    <button
                      key={ph}
                      type="button"
                      onClick={() => insertPlaceholder(ph)}
                      className="px-1.5 py-0.5 rounded text-xs transition-opacity hover:opacity-70"
                      style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--gold)', border: '1px solid var(--border)', fontFamily: 'monospace' }}
                    >{ph}</button>
                  ))}
                </div>
              </div>
            </div>

            <div className="flex justify-end pt-1">
              <SaveBtn onClick={() => emailMutation.mutate(emailForm)} pending={emailMutation.isPending} saved={savedTab === 'email'} />
            </div>
          </div>

          {/* Send Test Email */}
          <div className="rounded-2xl p-5 space-y-3" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <div>
              <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Send Test Email</p>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                Sends a test email via Brevo using your current template and images. Requires a saved API key.
              </p>
            </div>
            <div className="flex gap-2">
              <input
                type="email"
                value={testEmailAddr}
                onChange={e => setTestEmailAddr(e.target.value)}
                placeholder="you@example.com"
                className="flex-1 px-3 py-2 rounded-lg text-sm outline-none"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
              />
              <button
                onClick={handleTestEmail}
                disabled={!testEmailAddr || testEmailStatus === 'sending'}
                className="flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-medium transition-all disabled:opacity-50"
                style={{ backgroundColor: 'rgba(139,92,246,0.12)', color: '#8b5cf6', border: '1px solid rgba(139,92,246,0.25)' }}
              >
                {testEmailStatus === 'sending' ? <RefreshCw size={13} className="animate-spin" /> : <Send size={13} />}
                {testEmailStatus === 'sending' ? 'Sending\u2026' : 'Send Test'}
              </button>
            </div>
            {testEmailStatus === 'ok' && (
              <p className="flex items-center gap-1.5 text-xs" style={{ color: '#10b981' }}><Check size={12} /> {testEmailMsg}</p>
            )}
            {testEmailStatus === 'err' && (
              <p className="flex items-center gap-1.5 text-xs" style={{ color: '#ef4444' }}><X size={12} /> {testEmailMsg}</p>
            )}
          </div>
        </>
      )}

      {/* ══ SITE TAB ══ */}
      {activeTab === 'site' && (
        <div className="rounded-2xl p-5 space-y-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <div className="space-y-3">
            <SubLabel>Currency</SubLabel>
            <div className="grid grid-cols-2 gap-4">
              <SelectField
                label="Default Currency"
                value={siteForm.currency}
                onChange={handleCurrencyChange}
                options={CURRENCIES.map(c => ({ value: c.code, label: c.label }))}
                hint="Used for all financial figures across the platform."
              />
              <Field
                label="Currency Symbol Override"
                value={siteForm.currency_symbol}
                onChange={v => setSiteForm(p => ({ ...p, currency_symbol: v }))}
                placeholder="\u00a3"
                hint="Auto-filled above. Edit to customise the display symbol."
              />
            </div>
            <div className="flex items-center gap-2.5">
              <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Preview:</span>
              <span className="px-3 py-1 rounded-lg text-sm font-semibold tabular-nums" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-primary)', border: '1px solid var(--border)' }}>
                {siteForm.currency_symbol}1,234.56 {siteForm.currency}
              </span>
            </div>
          </div>

          <Divider />

          <SelectField
            label="Date Format"
            value={siteForm.date_format}
            onChange={v => setSiteForm(p => ({ ...p, date_format: v }))}
            options={DATE_FORMATS.map(d => ({ value: d.value, label: d.label }))}
            hint="Applied to all dates in the platform and generated documents."
          />

          <Divider />

          <Field
            label="Default Timezone"
            value={siteForm.timezone}
            onChange={v => setSiteForm(p => ({ ...p, timezone: v }))}
            placeholder="Europe/London"
            hint="IANA timezone identifier — e.g. Europe/London · America/New_York · Australia/Sydney"
          />

          <Divider />

          {/* Sidebar Page Visibility */}
          <div className="space-y-3">
            <div>
              <SubLabel>Admin Sidebar Visibility</SubLabel>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                Toggle which pages appear in the admin sidebar. Hidden pages are still accessible via direct URL.
              </p>
            </div>
            <div className="grid grid-cols-2 gap-2">
              {([
                // Pages available to all Admin users
                { key: 'companies',         label: 'Companies',      superAdminOnly: false },
                { key: 'documents',         label: 'Documents',      superAdminOnly: false },
                { key: 'prompts',           label: 'Prompt Library', superAdminOnly: false },
                { key: 'projects',          label: 'Projects',       superAdminOnly: false },
                { key: 'templates',         label: 'Templates',      superAdminOnly: false },
                { key: 'find',              label: 'Find Company',   superAdminOnly: false },
                { key: 'users',             label: 'Users',          superAdminOnly: false },
                // Super Admin-only pages — only show to Super Admins
                { key: 'pricing',           label: 'Pricing',        superAdminOnly: true },
                { key: 'ai-configurations', label: 'AI Config',      superAdminOnly: true },
                { key: 'application-monitoring', label: 'Application Monitoring', superAdminOnly: true },
                { key: 'storage',           label: 'Storage',        superAdminOnly: true },
                { key: 'support',           label: 'Support',        superAdminOnly: true },
                { key: 'system-logs',       label: 'System Logs',    superAdminOnly: true },
              ] as { key: string; label: string; superAdminOnly: boolean }[])
              .filter(p => !p.superAdminOnly || isSuperAdmin)
              .map(({ key, label }) => {
                const hidden = siteForm.hidden_pages.includes(key as string);
                return (
                  <button
                    key={key}
                    type="button"
                    onClick={() => {
                      setSiteForm(p => ({
                        ...p,
                        hidden_pages: hidden
                          ? p.hidden_pages.filter(k => k !== (key as string))
                          : [...p.hidden_pages, key as string],
                      }));
                    }}
                    className="flex items-center justify-between gap-3 px-3 py-2.5 rounded-xl text-sm transition-all"
                    style={{
                      backgroundColor: hidden ? 'var(--bg-elevated)' : 'var(--gold-8)',
                      border: `1px solid ${hidden ? 'var(--border)' : 'var(--gold-30)'}`,
                      color: hidden ? 'var(--text-muted)' : 'var(--text-primary)',
                    }}
                  >
                    <span className="font-medium text-xs">{label}</span>
                    <span
                      className="text-xs px-1.5 py-0.5 rounded-md font-medium"
                      style={{
                        backgroundColor: hidden ? 'rgba(90,86,82,0.15)' : 'var(--gold-15)',
                        color: hidden ? 'var(--text-muted)' : 'var(--gold)',
                      }}
                    >
                      {hidden ? 'Hidden' : 'Visible'}
                    </span>
                  </button>
                );
              })}
            </div>
          </div>

          <Divider />

          {/* Client App Module Visibility */}
          <div className="space-y-3">
            <div>
              <SubLabel>Client App Module Visibility</SubLabel>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                Toggle which modules appear in the project workspace sidebar for Admin and Client users. Hidden modules are still accessible via direct URL.
              </p>
            </div>
            <div className="grid grid-cols-2 gap-2">
              {([
                { key: 'adjudication', label: 'Adjudication' },
              ] as { key: string; label: string }[])
              .map(({ key, label }) => {
                const hidden = siteForm.hidden_pages.includes(key);
                return (
                  <button
                    key={key}
                    type="button"
                    onClick={() => {
                      setSiteForm(p => ({
                        ...p,
                        hidden_pages: hidden
                          ? p.hidden_pages.filter(k => k !== key)
                          : [...p.hidden_pages, key],
                      }));
                    }}
                    className="flex items-center justify-between gap-3 px-3 py-2.5 rounded-xl text-sm transition-all"
                    style={{
                      backgroundColor: hidden ? 'var(--bg-elevated)' : 'var(--gold-8)',
                      border: `1px solid ${hidden ? 'var(--border)' : 'var(--gold-30)'}`,
                      color: hidden ? 'var(--text-muted)' : 'var(--text-primary)',
                    }}
                  >
                    <span className="font-medium text-xs">{label}</span>
                    <span
                      className="text-xs px-1.5 py-0.5 rounded-md font-medium"
                      style={{
                        backgroundColor: hidden ? 'rgba(90,86,82,0.15)' : 'var(--gold-15)',
                        color: hidden ? 'var(--text-muted)' : 'var(--gold)',
                      }}
                    >
                      {hidden ? 'Hidden' : 'Visible'}
                    </span>
                  </button>
                );
              })}
            </div>
          </div>

          <div className="flex justify-end pt-1">
            <SaveBtn onClick={() => siteMutation.mutate(siteForm)} pending={siteMutation.isPending} saved={savedTab === 'site'} />
          </div>
        </div>
      )}

      {/* Feature Availability — Super Admin ONLY. Matches the backend
          management API's own `role:Super Admin` authorization; Admin never
          sees this tab button (filtered above) or its content (guarded here
          too, in case of a stale localStorage tab selection from a prior
          session with a different role). */}
      {activeTab === 'feature-availability' && (
        isSuperAdmin
          ? <FeatureAvailabilityManager />
          : <p className="text-sm" style={{ color: 'var(--text-muted)' }}>You don&apos;t have permission to view this section.</p>
      )}

    </div>
  );
}
