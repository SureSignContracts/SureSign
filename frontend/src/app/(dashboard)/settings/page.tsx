'use client';

import { useState, useEffect, useRef } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Settings, Save, Check, Upload, X, Palette, Building2, KeyRound, ScrollText, Lock, BookOpen, Globe, Eye } from 'lucide-react';
import Link from 'next/link';
import api from '@/lib/api';
import toast from 'react-hot-toast';
import PasswordStrengthChecker, { checkPassword, isPasswordValid } from '@/components/ui/PasswordStrengthChecker';
import TimezoneSelect from '@/components/shared/TimezoneSelect';
import { SUPPORTED_CURRENCIES, currencyLabel } from '@/lib/currency';
import { useAuthStore } from '@/store/authStore';
import CustomUrlSection from '@/components/settings/CustomUrlSection';
import BrandingPreviewPanel from '@/components/settings/BrandingPreviewPanel';
import Select from '@/components/ui/Select';
import { getErrorMessage } from '@/lib/getErrorMessage';

type Tab = 'branding' | 'preview' | 'information' | 'preferences' | 'password';

interface BrandingData {
  company_name: string;
  description: string;
  tagline: string;
  primary_color: string;
  email_footer: string;
  logo_url: string | null;
  cover_url: string | null;
  header_url: string | null;
  footer_url: string | null;
  contact_email: string;
  contact_phone: string;
  website: string;
  address: string;
  city: string;
  state: string;
  postcode: string;
  country: string;
  vat_number: string;
  timezone: string;
  // `currency` is the raw override (null/empty = inheriting the platform
  // default). `effective_currency` is what actually applies right now.
  currency: string | null;
  effective_currency: string;
}

function Field({
  label, value, onChange, placeholder, type = 'text', textarea,
}: {
  label: string; value: string; onChange: (v: string) => void;
  placeholder?: string; type?: string; textarea?: boolean;
}) {
  const cls = "w-full px-3 py-2.5 rounded-lg text-sm outline-none resize-none transition-colors duration-200 focus:border-[var(--gold)]";
  const sty = { backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' };
  return (
    <div>
      <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>{label}</label>
      {textarea
        ? <textarea value={value} onChange={e => onChange(e.target.value)} placeholder={placeholder} rows={4} className={cls} style={sty} />
        : <input type={type} value={value} onChange={e => onChange(e.target.value)} placeholder={placeholder} className={cls} style={sty} />
      }
    </div>
  );
}

function ImageUploader({
  label, description, currentUrl, fieldKey, aspect = 'square',
}: {
  label: string; description: string; currentUrl: string | null;
  fieldKey: 'logo' | 'cover' | 'letterhead-header' | 'letterhead-footer';
  aspect?: 'square' | 'wide';
}) {
  const qc = useQueryClient();
  const ref = useRef<HTMLInputElement>(null);
  const [preview, setPreview] = useState<string | null>(currentUrl);
  const [uploading, setUploading] = useState(false);

  useEffect(() => { setPreview(currentUrl); }, [currentUrl]);

  const handleChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setPreview(URL.createObjectURL(file));
    setUploading(true);
    try {
      const fd = new FormData();
      const paramName = fieldKey === 'logo' ? 'logo' : fieldKey === 'cover' ? 'cover' : 'image';
      fd.append(paramName, file);
      await api.post(`/organization/${fieldKey}`, fd, { headers: { 'Content-Type': 'multipart/form-data' } });
      qc.invalidateQueries({ queryKey: ['branding'] });
    } catch {
      setPreview(currentUrl);
    } finally {
      setUploading(false);
    }
  };

  return (
    <div>
      <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>{label}</label>
      <p className="text-xs mb-2" style={{ color: 'var(--text-muted)' }}>{description}</p>
      <div
        className={`group relative rounded-xl overflow-hidden flex items-center justify-center cursor-pointer border-2 border-dashed transition-all duration-200 hover:border-[var(--gold)] hover:scale-[1.01] active:scale-[0.99] ${aspect === 'wide' ? 'h-24 w-full' : 'w-28 h-28'}`}
        style={{
          borderColor: preview ? 'transparent' : 'var(--border)',
          // Fixed light backdrop when a preview is set — deliberately NOT
          // var(--bg-elevated), which goes near-black in dark mode. Most
          // uploaded logos/covers are designed for a light background
          // (dark artwork on transparency) and would otherwise disappear
          // entirely in dark mode. The empty "Click to upload" state still
          // follows the theme, since there's no image contrast to protect.
          backgroundColor: preview ? '#ffffff' : 'var(--bg-elevated)',
        }}
        onClick={() => ref.current?.click()}
      >
        {preview ? (
          <img src={preview} alt={label} className="w-full h-full object-contain" />
        ) : (
          <div className="flex flex-col items-center gap-1.5 p-3">
            <Upload size={18} className="transition-transform duration-200 group-hover:scale-110" style={{ color: 'var(--text-muted)' }} />
            <span className="text-xs text-center" style={{ color: 'var(--text-muted)' }}>
              {uploading ? 'Uploading…' : 'Click to upload'}
            </span>
          </div>
        )}
        {uploading && (
          <div className="absolute inset-0 flex items-center justify-center" style={{ backgroundColor: 'rgba(0,0,0,0.4)' }}>
            <div className="w-5 h-5 border-2 rounded-full animate-spin" style={{ borderColor: 'transparent', borderTopColor: 'white' }} />
          </div>
        )}
      </div>
      <input ref={ref} type="file" accept="image/*" className="hidden" onChange={handleChange} />
    </div>
  );
}

export default function SettingsPage() {
  const [tab, setTab] = useState<Tab>('branding');
  const [saved, setSaved] = useState(false);
  const qc = useQueryClient();

  const { data: b, isLoading } = useQuery<BrandingData>({
    queryKey: ['branding'],
    queryFn: () => api.get('/organization/branding').then(r => r.data?.data ?? r.data),
  });

  const [brandForm, setBrandForm] = useState({
    company_name: '', description: '', tagline: '', primary_color: '#000000', email_footer: '',
  });
  const [infoForm, setInfoForm] = useState({
    contact_email: '', contact_phone: '', website: '', address: '', city: '', state: '', postcode: '', country: '', vat_number: '', timezone: 'Europe/London',
    // '' means "use the platform default" — never pre-filled from country.
    currency: '',
  });

  useEffect(() => {
    if (!b) return;
    setBrandForm({
      company_name: b.company_name ?? '',
      description:  b.description  ?? '',
      tagline:      b.tagline      ?? '',
      primary_color: b.primary_color ?? '#000000',
      email_footer: b.email_footer ?? '',
    });
    setInfoForm({
      contact_email: b.contact_email ?? '',
      contact_phone: b.contact_phone ?? '',
      website:       b.website       ?? '',
      address:       b.address       ?? '',
      city:          b.city          ?? '',
      state:         b.state         ?? '',
      postcode:      b.postcode      ?? '',
      country:       b.country       ?? '',
      vat_number:    b.vat_number    ?? '',
      timezone:      b.timezone      ?? 'Europe/London',
      currency:      b.currency      ?? '',
    });
    // Apply accent colour for this session only — NOT stored in localStorage
    if (b.primary_color) {
      document.documentElement.style.setProperty('--gold', b.primary_color);
      const isLight = (c: string) => {
        const hex = c.replace('#', '');
        const r = parseInt(hex.slice(0, 2), 16);
        const g = parseInt(hex.slice(2, 4), 16);
        const bl = parseInt(hex.slice(4, 6), 16);
        return (r * 299 + g * 587 + bl * 114) / 1000 > 128;
      };
      document.documentElement.style.setProperty('--accent-fg', isLight(b.primary_color) ? '#0a0a0a' : '#ffffff');
    }
  }, [b]);

  const brandMutation = useMutation({
    mutationFn: (p: typeof brandForm) => api.put('/organization/branding', p),
    onSuccess: (_, p) => {
      // Apply immediately for this client's session
      document.documentElement.style.setProperty('--gold', p.primary_color);
      const isLight = (c: string) => {
        const hex = c.replace('#', '');
        const r = parseInt(hex.slice(0, 2), 16);
        const g = parseInt(hex.slice(2, 4), 16);
        const bl = parseInt(hex.slice(4, 6), 16);
        return (r * 299 + g * 587 + bl * 114) / 1000 > 128;
      };
      document.documentElement.style.setProperty('--accent-fg', isLight(p.primary_color) ? '#0a0a0a' : '#ffffff');
      qc.invalidateQueries({ queryKey: ['branding'] });
      setSaved(true);
      setTimeout(() => setSaved(false), 2500);
    },
  });

  const infoMutation = useMutation({
    mutationFn: (p: typeof infoForm) => api.put('/organization', {
      email: p.contact_email,
      phone: p.contact_phone,
      website: p.website,
      address: p.address,
      city: p.city,
      state: p.state,
      postcode: p.postcode,
      country: p.country,
      abn: p.vat_number,
      timezone: p.timezone,
      // '' means "use the platform default" in the UI — send null, not '',
      // since the backend's `size:3` validation rejects an empty string.
      currency: p.currency || null,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['branding'] });
      // Organisation timezone changes take effect immediately for every
      // user who inherits it — refresh this session's own cached copy too,
      // so it's reflected here without requiring a re-login.
      useAuthStore.getState().fetchUser();
      setSaved(true);
      setTimeout(() => setSaved(false), 2500);
    },
  });

  const handleSave = () => {
    if (tab === 'branding') brandMutation.mutate(brandForm);
    else if (tab === 'information') infoMutation.mutate(infoForm);
  };

  const isPending = brandMutation.isPending || infoMutation.isPending;

  const tabs: { id: Tab; label: string; icon: any }[] = [
    { id: 'branding',     label: 'Branding',    icon: Palette },
    { id: 'preview',      label: 'Branding Preview',     icon: Eye },
    { id: 'information',  label: 'Company Information',  icon: Building2 },
    { id: 'preferences',  label: 'My Preferences',        icon: Globe },
    { id: 'password',     label: 'Change Password',      icon: KeyRound },
  ];

  // ── My Preferences (personal timezone override) ──
  const { user, fetchUser } = useAuthStore();
  const [useOrgTimezone, setUseOrgTimezone] = useState(true);
  const [ownTimezone, setOwnTimezone] = useState('Europe/London');
  const [tzSaved, setTzSaved] = useState(false);

  useEffect(() => {
    if (!user) return;
    setUseOrgTimezone(!user.timezone);
    setOwnTimezone(user.timezone ?? user.effective_timezone ?? user.organization?.timezone ?? 'Europe/London');
  }, [user]);

  const timezoneMutation = useMutation({
    mutationFn: (timezone: string | null) => api.put('/auth/timezone', { timezone }),
    onSuccess: async () => {
      await fetchUser();
      setTzSaved(true);
      setTimeout(() => setTzSaved(false), 2500);
    },
  });

  // ── Change Password ──
  const [pwForm, setPwForm] = useState({ current: '', password: '', confirm: '' });
  const [pwErrors, setPwErrors] = useState<{ current?: string; password?: string; confirm?: string }>({});
  const [pwDone, setPwDone] = useState(false);

  const pwRules = checkPassword(pwForm.password);
  const pwValid = pwForm.password
    ? isPasswordValid(pwRules) && pwForm.password === pwForm.confirm && !!pwForm.current
    : false;

  const pwMutation = useMutation({
    mutationFn: () => api.put('/auth/password', {
      current_password: pwForm.current,
      password: pwForm.password,
      password_confirmation: pwForm.confirm,
    }),
    onSuccess: () => {
      setPwForm({ current: '', password: '', confirm: '' });
      setPwErrors({});
      setPwDone(true);
      setTimeout(() => setPwDone(false), 3000);
      toast.success('Password updated successfully.');
    },
    onError: (err: any) => {
      const errs = err?.response?.data?.errors ?? {};
      setPwErrors({
        current:  errs.current_password?.[0],
        password: errs.password?.[0],
        confirm:  errs.password_confirmation?.[0],
      });
      if (!Object.keys(errs).length) {
        toast.error(getErrorMessage(err, 'Failed to update password.'));
      }
    },
  });

  return (
    <div className="p-6 max-w-3xl mx-auto">
      <div className="flex items-center gap-3 mb-6 ss-animate-in">
        <div className="w-9 h-9 rounded-xl flex items-center justify-center transition-transform duration-300 hover:scale-105" style={{ backgroundColor: 'var(--bg-elevated)' }}>
          <Settings size={18} style={{ color: 'var(--text-secondary)' }} />
        </div>
        <div>
          <h1 className="text-2xl font-semibold" style={{ color: 'var(--text-primary)' }}>Settings</h1>
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Manage your organization preferences</p>
        </div>
      </div>

      {/* Tab bar */}
      <div className="flex flex-nowrap gap-1 p-1 rounded-full mb-6 w-fit ss-animate-in" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', animationDelay: '60ms' }}>
        {tabs.map(t => (
          <button key={t.id} onClick={() => { setTab(t.id); setSaved(false); }}
            className="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium transition-all duration-200 active:scale-[0.97] whitespace-nowrap"
            style={tab === t.id
              ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
              : { color: 'var(--text-secondary)' }
            }
          >
            <t.icon size={12} />
            {t.label}
          </button>
        ))}
      </div>

      <div key={tab} className="rounded-2xl p-6 ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', animationDelay: '100ms' }}>
        {isLoading ? (
          <div className="space-y-4">
            {[...Array(4)].map((_, i) => (
              <div key={i} className="h-10 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
            ))}
          </div>
        ) : tab === 'branding' ? (
          /* ── Company Branding ── */
          <div className="space-y-6">
            {/* Logo + Cover */}
            <div className="grid grid-cols-2 gap-6">
              <ImageUploader
                label="Company Logo"
                description="Appears in the sidebar on all documents. PNG/JPG/SVG, max 2MB."
                currentUrl={b?.logo_url ?? null}
                fieldKey="logo"
              />
              <ImageUploader
                label="Cover / Banner Image"
                description="Used on reports and document covers. Max 5MB."
                currentUrl={b?.cover_url ?? null}
                fieldKey="cover"
                aspect="wide"
              />
            </div>

            <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1.5rem' }} className="space-y-4">
              <Field label="Display Name" value={brandForm.company_name} onChange={v => setBrandForm(f => ({ ...f, company_name: v }))} placeholder="Acme Construction Ltd" />
              <Field label="Company Description" value={brandForm.description} onChange={v => setBrandForm(f => ({ ...f, description: v }))} placeholder="Brief description of your company…" textarea />
              <Field label="Tagline / Slogan" value={brandForm.tagline} onChange={v => setBrandForm(f => ({ ...f, tagline: v }))} placeholder="Building the future" />
            </div>

            <CustomUrlSection />

            {/* Accent colour */}
            <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1.5rem' }}>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>Company Accent Colour</label>
              <p className="text-xs mb-3" style={{ color: 'var(--text-muted)' }}>
                Personalises buttons and highlights for your company portal. This colour is tied only to your account.
              </p>
              <div className="flex items-center gap-3">
                <input
                  type="color"
                  value={brandForm.primary_color}
                  onChange={e => {
                    const c = e.target.value;
                    setBrandForm(f => ({ ...f, primary_color: c }));
                    // Live preview — not persisted until Save
                    document.documentElement.style.setProperty('--gold', c);
                    const isLight = (hex: string) => {
                      const h = hex.replace('#', '');
                      const r = parseInt(h.slice(0,2), 16), g = parseInt(h.slice(2,4), 16), bl = parseInt(h.slice(4,6), 16);
                      return (r*299+g*587+bl*114)/1000 > 128;
                    };
                    document.documentElement.style.setProperty('--accent-fg', isLight(c) ? '#0a0a0a' : '#ffffff');
                  }}
                  className="w-10 h-10 rounded-lg cursor-pointer border-0 p-0.5"
                  style={{ backgroundColor: 'var(--bg-elevated)' }}
                />
                <input
                  value={brandForm.primary_color}
                  onChange={e => setBrandForm(f => ({ ...f, primary_color: e.target.value }))}
                  placeholder="#000000"
                  className="w-36 px-3 py-2.5 rounded-lg text-sm outline-none font-mono"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                />
                <div className="flex items-center gap-2 px-3 py-2 rounded-lg flex-1" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                  <div className="w-5 h-5 rounded-full flex-shrink-0" style={{ backgroundColor: brandForm.primary_color }} />
                  <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Live preview — save to persist</span>
                </div>
              </div>
            </div>

            {/* Email footer */}
            <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1.5rem' }}>
              <Field
                label="Email Footer / Signature"
                value={brandForm.email_footer}
                onChange={v => setBrandForm(f => ({ ...f, email_footer: v }))}
                placeholder="Kind regards,&#10;The Acme Construction Team&#10;&#10;Company Reg: 12345678 | VAT: GB123456789"
                textarea
              />
              <p className="text-xs mt-1.5" style={{ color: 'var(--text-muted)' }}>Appended to all outgoing notification emails.</p>
            </div>

            {/* Letterhead */}
            <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1.5rem' }}>
              <p className="text-xs font-semibold mb-1" style={{ color: 'var(--text-secondary)' }}>Letterhead Branding</p>
              <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
                Header and footer images placed on generated letters, applications and notices.
              </p>
              <div className="space-y-4">
                <ImageUploader
                  label="Letterhead Header Image"
                  description="Banner at the top of each document (recommended: 2480×300px)"
                  currentUrl={b?.header_url ?? null}
                  fieldKey="letterhead-header"
                  aspect="wide"
                />
                <ImageUploader
                  label="Letterhead Footer Image"
                  description="Footer at the bottom of each document (recommended: 2480×200px)"
                  currentUrl={b?.footer_url ?? null}
                  fieldKey="letterhead-footer"
                  aspect="wide"
                />
              </div>
            </div>
          </div>
        ) : tab === 'preview' ? (
          /* ── Branding Preview (Organisation URL Branding, Phase 4) ── */
          <BrandingPreviewPanel
            companyName={brandForm.company_name}
            logoUrl={b?.logo_url ?? null}
            accentColor={brandForm.primary_color}
          />
        ) : tab === 'information' ? (
          /* ── Company Information ── */
          <div className="space-y-5">
            <div>
              <p className="text-xs font-semibold mb-3" style={{ color: 'var(--text-secondary)' }}>Contact Details</p>
              <div className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <Field label="Contact Email" type="email" value={infoForm.contact_email} onChange={v => setInfoForm(f => ({ ...f, contact_email: v }))} placeholder="info@company.com" />
                  <Field label="Contact Number" type="tel" value={infoForm.contact_phone} onChange={v => setInfoForm(f => ({ ...f, contact_phone: v }))} placeholder="+44 20 7946 0000" />
                </div>
                <Field label="Website" type="url" value={infoForm.website} onChange={v => setInfoForm(f => ({ ...f, website: v }))} placeholder="https://company.com" />
              </div>
            </div>

            <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1.25rem' }}>
              <p className="text-xs font-semibold mb-3" style={{ color: 'var(--text-secondary)' }}>Registered Address</p>
              <div className="space-y-3">
                <Field label="Street Address" value={infoForm.address} onChange={v => setInfoForm(f => ({ ...f, address: v }))} placeholder="10 Construction Way" />
                <div className="grid grid-cols-3 gap-3">
                  <Field label="City" value={infoForm.city} onChange={v => setInfoForm(f => ({ ...f, city: v }))} placeholder="London" />
                  <Field label="State / County" value={infoForm.state} onChange={v => setInfoForm(f => ({ ...f, state: v }))} placeholder="England" />
                  <Field label="Postcode / ZIP" value={infoForm.postcode} onChange={v => setInfoForm(f => ({ ...f, postcode: v }))} placeholder="EC1A 1BB" />
                </div>
                <Field label="Country" value={infoForm.country} onChange={v => setInfoForm(f => ({ ...f, country: v }))} placeholder="United Kingdom" />
              </div>
            </div>

            <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1.25rem' }}>
              <p className="text-xs font-semibold mb-3" style={{ color: 'var(--text-secondary)' }}>Legal & Tax</p>
              <Field label="VAT / Tax Number" value={infoForm.vat_number} onChange={v => setInfoForm(f => ({ ...f, vat_number: v }))} placeholder="GB123456789" />
            </div>

            <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1.25rem' }}>
              <p className="text-xs font-semibold mb-3" style={{ color: 'var(--text-secondary)' }}>Regional Settings</p>
              <div>
                <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>Organisation Timezone</label>
                <TimezoneSelect value={infoForm.timezone} onChange={v => setInfoForm(f => ({ ...f, timezone: v }))} />
                <p className="mt-1.5 text-xs" style={{ color: 'var(--text-muted)' }}>
                  Applies to every user in your organisation unless they set their own override under My Preferences.
                </p>
              </div>

              <div className="mt-4">
                <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>Organisation Default Currency</label>
                <Select
                  value={infoForm.currency}
                  onChange={e => setInfoForm(f => ({ ...f, currency: e.target.value }))}
                  className="w-full"
                >
                  <option value="">Use platform default ({b?.effective_currency ?? 'GBP'})</option>
                  {SUPPORTED_CURRENCIES.map(code => (
                    <option key={code} value={code}>{code} — {currencyLabel(code)}</option>
                  ))}
                </Select>
                <p className="mt-1.5 text-xs" style={{ color: 'var(--text-muted)' }}>
                  Applies to every project in your organisation that doesn&apos;t have its own currency override set.
                  Changing this never affects a project that already has an explicit currency.
                </p>
              </div>
            </div>
          </div>
        ) : tab === 'preferences' ? (
          /* ── My Preferences ── */
          <div className="space-y-5 max-w-sm">
            <div>
              <p className="text-xs font-semibold mb-3" style={{ color: 'var(--text-secondary)' }}>Your Timezone</p>
              <label className="flex items-start gap-2.5 cursor-pointer mb-3">
                <input
                  type="checkbox"
                  checked={useOrgTimezone}
                  onChange={e => setUseOrgTimezone(e.target.checked)}
                  className="mt-0.5"
                />
                <span className="text-sm" style={{ color: 'var(--text-primary)' }}>
                  Use company timezone
                  <span className="block text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                    {user?.organization?.timezone ?? 'Europe/London'}
                  </span>
                </span>
              </label>

              {!useOrgTimezone && (
                <div>
                  <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>Your Timezone</label>
                  <TimezoneSelect value={ownTimezone} onChange={setOwnTimezone} />
                </div>
              )}
            </div>

            <div className="pt-2">
              <button
                onClick={() => timezoneMutation.mutate(useOrgTimezone ? null : ownTimezone)}
                disabled={timezoneMutation.isPending}
                className="flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium transition-opacity disabled:opacity-60"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
              >
                {tzSaved ? <Check size={15} /> : <Globe size={15} />}
                {tzSaved ? 'Saved!' : timezoneMutation.isPending ? 'Saving…' : 'Save Timezone'}
              </button>
            </div>
          </div>
        ) : tab === 'password' ? (
          /* ── Change Password ── */
          <div className="space-y-5 max-w-sm">
            <div>
              <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
                Current Password
              </label>
              <input
                type="password"
                value={pwForm.current}
                onChange={e => { setPwForm(f => ({ ...f, current: e.target.value })); setPwErrors(p => ({ ...p, current: undefined })); }}
                placeholder="Enter your current password"
                className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
                style={{ backgroundColor: 'var(--bg-elevated)', border: `1px solid ${pwErrors.current ? '#ef4444' : 'var(--border)'}`, color: 'var(--text-primary)' }}
              />
              {pwErrors.current && <p className="mt-1 text-xs" style={{ color: '#ef4444' }}>{pwErrors.current}</p>}
            </div>

            <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1.25rem' }} className="space-y-4">
              <div>
                <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
                  New Password
                </label>
                <input
                  type="password"
                  value={pwForm.password}
                  onChange={e => { setPwForm(f => ({ ...f, password: e.target.value })); setPwErrors(p => ({ ...p, password: undefined })); }}
                  placeholder="Min 8 chars, mixed case, number, symbol"
                  className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: `1px solid ${pwErrors.password ? '#ef4444' : 'var(--border)'}`, color: 'var(--text-primary)' }}
                />
                {pwErrors.password && <p className="mt-1 text-xs" style={{ color: '#ef4444' }}>{pwErrors.password}</p>}
                <PasswordStrengthChecker password={pwForm.password} />
              </div>

              <div>
                <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
                  Confirm New Password
                </label>
                <input
                  type="password"
                  value={pwForm.confirm}
                  onChange={e => { setPwForm(f => ({ ...f, confirm: e.target.value })); setPwErrors(p => ({ ...p, confirm: undefined })); }}
                  placeholder="Repeat new password"
                  className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: `1px solid ${pwErrors.confirm ? '#ef4444' : 'var(--border)'}`, color: 'var(--text-primary)' }}
                />
                {pwErrors.confirm && <p className="mt-1 text-xs" style={{ color: '#ef4444' }}>{pwErrors.confirm}</p>}
                <PasswordStrengthChecker
                  password={pwForm.password}
                  confirmPassword={pwForm.confirm}
                  showConfirmMatch
                />
              </div>
            </div>

            <div className="pt-2">
              <button
                onClick={() => pwMutation.mutate()}
                disabled={!pwValid || pwMutation.isPending}
                className="flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium transition-opacity disabled:opacity-50"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
              >
                {pwDone ? <Check size={15} /> : <KeyRound size={15} />}
                {pwDone ? 'Password Updated!' : pwMutation.isPending ? 'Updating…' : 'Update Password'}
              </button>
            </div>
          </div>
        ) : null}

        {tab !== 'password' && tab !== 'preferences' && tab !== 'preview' && (
          <div className="mt-6 flex justify-end">
            <button
              onClick={handleSave}
              disabled={isPending}
              className="flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 active:scale-[0.97] disabled:opacity-60"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
            >
              {saved ? <Check size={15} /> : <Save size={15} />}
              {saved ? 'Saved!' : isPending ? 'Saving…' : 'Save Changes'}
            </button>
          </div>
        )}
      </div>

      {/* Legal & Info */}
      <div className="mt-8 pt-6" style={{ borderTop: '1px solid var(--border)' }}>
        <h2 className="text-xs font-semibold uppercase tracking-wider mb-3" style={{ color: 'var(--text-muted)' }}>
          Legal &amp; Info
        </h2>
        <div className="grid grid-cols-3 gap-3">
          {[
            { href: '/app/settings/releases', icon: ScrollText, label: 'Release Notes', desc: 'What\'s new in SureSign Contracts' },
            { href: '/app/settings/privacy',  icon: Lock,        label: 'Privacy Policy', desc: 'How we handle your data' },
            { href: '/app/settings/terms',    icon: BookOpen,    label: 'Terms of Use',   desc: 'Platform usage terms' },
          ].map(({ href, icon: Icon, label, desc }) => (
            <Link
              key={href}
              href={href}
              className="flex items-start gap-3 px-4 py-3 rounded-xl transition-colors hover:bg-[var(--bg-hover)]"
              style={{ border: '1px solid var(--border)' }}
            >
              <div className="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5"
                style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}>
                <Icon size={14} />
              </div>
              <div>
                <div className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{label}</div>
                <div className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{desc}</div>
              </div>
            </Link>
          ))}
        </div>
      </div>
    </div>
  );
}

