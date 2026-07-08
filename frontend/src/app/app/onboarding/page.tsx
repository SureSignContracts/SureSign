'use client';

import { useState, useRef } from 'react';
import { useRouter } from 'next/navigation';
import { useQueryClient } from '@tanstack/react-query';
import { useAuthStore } from '@/store/authStore';
import api from '@/lib/api';
import {
  User, Building2, Palette, ArrowRight, ArrowLeft,
  Check, Upload, X, AlertCircle,
} from 'lucide-react';
import PasswordStrengthChecker, { checkPassword, isPasswordValid } from '@/components/ui/PasswordStrengthChecker';
import { Card } from '@/components/ui/Card';

// ─── Form state types ────────────────────────────────────────────────────────

interface ProfileForm {
  first_name: string;
  last_name: string;
  phone: string;
  email: string;
  password: string;
  password_confirmation: string;
  address: string;
  city: string;
  province: string;
  postal_code: string;
  country: string;
}
interface CompanyForm {
  name: string;
  acn: string;
  abn: string;
  email: string;
  phone: string;
  website: string;
  address: string;
  city: string;
  state: string;
  postcode: string;
  country: string;
}
interface BrandingForm {
  primaryColor: string;
  logoFile: File | null;
  logoPreview: string | null;
  headerFile: File | null;
  headerPreview: string | null;
  footerFile: File | null;
  footerPreview: string | null;
}

const STEPS = [
  { id: 1, label: 'Your Profile', icon: User },
  { id: 2, label: 'Company',      icon: Building2 },
  { id: 3, label: 'Branding',     icon: Palette },
];

// ─── Field component ─────────────────────────────────────────────────────────

function Field({
  label, value, onChange, placeholder, type = 'text', required, error,
}: {
  label: string; value: string; onChange: (v: string) => void;
  placeholder?: string; type?: string; required?: boolean; error?: string;
}) {
  return (
    <div>
      <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
        {label}{required && <span style={{ color: '#ef4444' }}> *</span>}
      </label>
      <input
        type={type}
        value={value}
        onChange={e => onChange(e.target.value)}
        placeholder={placeholder}
        className="w-full px-3 py-2.5 rounded-lg text-sm outline-none transition-colors"
        style={{
          backgroundColor: 'var(--bg-elevated)',
          border: `1px solid ${error ? '#ef4444' : 'var(--border)'}`,
          color: 'var(--text-primary)',
        }}
      />
      {error && <p className="mt-1 text-xs" style={{ color: '#ef4444' }}>{error}</p>}
    </div>
  );
}

// ─── Image upload card ────────────────────────────────────────────────────────

function ImageUploadCard({
  label, description, preview, onFile, onClear, aspect = 'square',
}: {
  label: string; description: string;
  preview: string | null;
  onFile: (f: File, url: string) => void;
  onClear: () => void;
  aspect?: 'square' | 'wide';
}) {
  const ref = useRef<HTMLInputElement>(null);
  return (
    <div>
      <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>{label}</label>
      <p className="text-xs mb-2" style={{ color: 'var(--text-muted)' }}>{description}</p>
      <div
        className={`relative rounded-xl overflow-hidden flex items-center justify-center cursor-pointer border-2 border-dashed transition-all ${aspect === 'wide' ? 'h-28 w-full' : 'w-32 h-32'}`}
        style={{ borderColor: preview ? 'transparent' : 'var(--border)', backgroundColor: 'var(--bg-elevated)' }}
        onClick={() => ref.current?.click()}
      >
        {preview ? (
          <>
            <img src={preview} alt={label} className="w-full h-full object-contain" />
            <button
              type="button"
              onClick={e => { e.stopPropagation(); onClear(); }}
              className="absolute top-1 right-1 w-5 h-5 rounded-full flex items-center justify-center"
              style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}
            >
              <X size={10} color="white" />
            </button>
          </>
        ) : (
          <div className="flex flex-col items-center gap-1.5 p-4">
            <Upload size={20} style={{ color: 'var(--text-muted)' }} />
            <span className="text-xs text-center" style={{ color: 'var(--text-muted)' }}>Click to upload</span>
          </div>
        )}
      </div>
      <input ref={ref} type="file" accept="image/*" className="hidden"
        onChange={e => { const f = e.target.files?.[0]; if (f) onFile(f, URL.createObjectURL(f)); }} />
    </div>
  );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function OnboardingPage() {
  const router  = useRouter();
  const { fetchUser, user } = useAuthStore();
  const qc      = useQueryClient();
  const [step, setStep] = useState(1);
  const [saving, setSaving] = useState(false);
  const [globalError, setGlobalError] = useState<string | null>(null);

  const [profileErrors, setProfileErrors]   = useState<Partial<Record<keyof ProfileForm, string>>>({});
  const [companyErrors, setCompanyErrors] = useState<Partial<Record<keyof CompanyForm, string>>>({});

  const [profile, setProfile] = useState<ProfileForm>({
    first_name: user?.first_name ?? '',
    last_name:  user?.last_name  ?? '',
    phone:      user?.phone      ?? '',
    email:      user?.email      ?? '',
    password:   '',
    password_confirmation: '',
    address:     user?.address     ?? '',
    city:        user?.city        ?? '',
    province:    user?.province    ?? '',
    postal_code: user?.postal_code ?? '',
    country:     user?.country     ?? '',
  });
  const [company, setCompany] = useState<CompanyForm>({
    name: '', acn: '', abn: '', email: '', phone: '', website: '',
    address: '', city: '', state: '', postcode: '', country: '',
  });
  const [branding, setBranding] = useState<BrandingForm>({
    primaryColor: '#0a0a0a',
    logoFile: null, logoPreview: null,
    headerFile: null, headerPreview: null,
    footerFile: null, footerPreview: null,
  });

  const setP = (k: keyof ProfileForm) => (v: string) => setProfile(f => ({ ...f, [k]: v }));
  const setO = (k: keyof CompanyForm) => (v: string) => setCompany(f => ({ ...f, [k]: v }));

  function validateProfile(): boolean {
    const errs: typeof profileErrors = {};
    if (!profile.first_name.trim()) errs.first_name = 'First name is required.';
    if (!profile.last_name.trim())  errs.last_name  = 'Last name is required.';
    if (!profile.email.trim())      errs.email      = 'Email is required.';
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(profile.email))
      errs.email = 'Please enter a valid email address.';
    if (profile.password) {
      const rules = checkPassword(profile.password);
      if (!isPasswordValid(rules))
        errs.password = 'Password does not meet all requirements.';
      if (profile.password !== profile.password_confirmation)
        errs.password_confirmation = 'Passwords do not match.';
    }
    setProfileErrors(errs);
    return Object.keys(errs).length === 0;
  }

  function validateCompany(): boolean {
    const errs: typeof companyErrors = {};
    if (!company.name.trim()) errs.name = 'Company name is required.';
    if (company.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(company.email))
      errs.email = 'Please enter a valid email address.';
    setCompanyErrors(errs);
    return Object.keys(errs).length === 0;
  }

  async function handleStep1() {
    if (!validateProfile()) return;
    setSaving(true);
    setGlobalError(null);
    try {
      await api.post('/organization/onboard/profile', {
        first_name:            profile.first_name,
        last_name:             profile.last_name,
        email:                 profile.email,
        phone:                 profile.phone      || undefined,
        password:              profile.password   || undefined,
        password_confirmation: profile.password   ? profile.password_confirmation : undefined,
        address:               profile.address    || undefined,
        city:                  profile.city       || undefined,
        province:              profile.province   || undefined,
        postal_code:           profile.postal_code || undefined,
        country:               profile.country    || undefined,
      });
      setStep(2);
    } catch (e: any) {
      const msgs = e?.response?.data?.errors;
      if (msgs) {
        const mapped: typeof profileErrors = {};
        if (msgs.first_name)            mapped.first_name            = msgs.first_name[0];
        if (msgs.last_name)             mapped.last_name             = msgs.last_name[0];
        if (msgs.email)                 mapped.email                 = msgs.email[0];
        if (msgs.phone)                 mapped.phone                 = msgs.phone[0];
        if (msgs.password)              mapped.password              = msgs.password[0];
        if (msgs.password_confirmation) mapped.password_confirmation = msgs.password_confirmation[0];
        setProfileErrors(mapped);
      } else {
        setGlobalError(e?.response?.data?.message ?? 'Failed to save profile. Please try again.');
      }
    } finally {
      setSaving(false);
    }
  }

  async function handleStep2() {
    if (!validateCompany()) return;
    setSaving(true);
    setGlobalError(null);
    try {
      await api.post('/organization/onboard/company', {
        name:     company.name,
        acn:      company.acn      || undefined,
        abn:      company.abn      || undefined,
        email:    company.email    || undefined,
        phone:    company.phone    || undefined,
        website:  company.website  || undefined,
        address:  company.address  || undefined,
        city:     company.city     || undefined,
        state:    company.state    || undefined,
        postcode: company.postcode || undefined,
        country:  company.country  || undefined,
      });
      setStep(3);
    } catch (e: any) {
      const msgs = e?.response?.data?.errors;
      if (msgs) {
        const mapped: typeof companyErrors = {};
        if (msgs.name)    mapped.name    = msgs.name[0];
        if (msgs.email)   mapped.email   = msgs.email[0];
        if (msgs.website) mapped.website = msgs.website[0];
        setCompanyErrors(mapped);
      } else {
        setGlobalError(e?.response?.data?.message ?? 'Failed to save company details. Please try again.');
      }
    } finally {
      setSaving(false);
    }
  }

  async function handleFinalize() {
    setSaving(true);
    setGlobalError(null);
    try {
      await api.put('/organization/branding', {
        primary_color: branding.primaryColor,
        company_name:  company.name,
      });

      if (branding.logoFile) {
        const fd = new FormData();
        fd.append('logo', branding.logoFile);
        await api.post('/organization/logo', fd, { headers: { 'Content-Type': 'multipart/form-data' } });
      }
      if (branding.headerFile) {
        const fd = new FormData();
        fd.append('image', branding.headerFile);
        await api.post('/organization/letterhead-header', fd, { headers: { 'Content-Type': 'multipart/form-data' } });
      }
      if (branding.footerFile) {
        const fd = new FormData();
        fd.append('image', branding.footerFile);
        await api.post('/organization/letterhead-footer', fd, { headers: { 'Content-Type': 'multipart/form-data' } });
      }

      await api.post('/organization/onboard/finalize');
      await fetchUser();
      qc.invalidateQueries({ queryKey: ['branding'] });
      router.push('/app');
    } catch (e: any) {
      setGlobalError(e?.response?.data?.message ?? 'Something went wrong. Please try again.');
    } finally {
      setSaving(false);
    }
  }

  function isLight(hex: string) {
    const h = hex.replace('#', '');
    if (h.length < 6) return true;
    const r = parseInt(h.slice(0,2),16), g = parseInt(h.slice(2,4),16), b = parseInt(h.slice(4,6),16);
    return (r*299 + g*587 + b*114)/1000 > 128;
  }

  return (
    <div className="min-h-screen flex items-start justify-center px-4 py-10"
         style={{ backgroundColor: 'var(--bg-base)' }}>
      <div className="w-full max-w-2xl">

        {/* Exit button */}
        <div className="mb-6 flex justify-end">
          <button
            onClick={() => { useAuthStore.setState({ token: null, user: null }); router.push('/login'); }}
            className="text-xs font-medium px-3 py-1.5 rounded-lg"
            style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)', border: '1px solid var(--border)' }}
          >
            Exit to Login
          </button>
        </div>

        {/* Header */}
        <div className="text-center mb-8">
          <div className="w-12 h-12 rounded-xl flex items-center justify-center mx-auto mb-4 text-lg font-bold"
               style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>S</div>
          <h1 className="text-3xl font-bold mb-2" style={{ color: 'var(--text-primary)' }}>Welcome to SureSign Contracts</h1>
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
            Let's set up your account before you get started
          </p>
        </div>

        {/* Step indicator */}
        <div className="flex items-center justify-center mb-8">
          {STEPS.map((s, i) => (
            <div key={s.id} className="flex items-center">
              <div className="flex flex-col items-center gap-1">
                <div
                  className="w-9 h-9 rounded-full flex items-center justify-center text-sm font-semibold transition-all"
                  style={
                    step > s.id
                      ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                      : step === s.id
                      ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', boxShadow: '0 0 0 4px rgba(0,0,0,0.1)' }
                      : { backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', border: '1px solid var(--border)' }
                  }
                >
                  {step > s.id ? <Check size={14} /> : <s.icon size={14} />}
                </div>
                <span className="text-xs font-medium whitespace-nowrap"
                      style={{ color: step === s.id ? 'var(--text-primary)' : 'var(--text-muted)' }}>
                  {s.label}
                </span>
              </div>
              {i < STEPS.length - 1 && (
                <div className="w-16 h-px mx-2 mb-5"
                     style={{ backgroundColor: step > s.id ? 'var(--gold)' : 'var(--border)' }} />
              )}
            </div>
          ))}
        </div>

        {/* Card */}
        <Card className="p-8">

          {/* Step 1: Your Profile */}
          {step === 1 && (
            <div className="space-y-5">
              <div className="mb-2">
                <h2 className="text-xl font-semibold" style={{ color: 'var(--text-primary)' }}>Your Profile</h2>
                <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>
                  Personal details for your account, stored on your user record
                </p>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <Field label="First Name" required
                  value={profile.first_name} onChange={setP('first_name')}
                  placeholder="John" error={profileErrors.first_name} />
                <Field label="Last Name" required
                  value={profile.last_name} onChange={setP('last_name')}
                  placeholder="Smith" error={profileErrors.last_name} />
              </div>

              <div className="grid grid-cols-2 gap-4">
                <Field label="Phone Number" type="tel"
                  value={profile.phone} onChange={setP('phone')}
                  placeholder="+44 20 7946 0000" error={profileErrors.phone} />
                <Field label="Email Address" required type="email"
                  value={profile.email} onChange={setP('email')}
                  placeholder="john@example.com" error={profileErrors.email} />
              </div>

              <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1.25rem' }} className="space-y-4">
                <p className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>
                  Set New Password
                </p>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <Field label="New Password" type="password"
                      value={profile.password} onChange={setP('password')}
                      placeholder="Min 8 chars, mixed case, number, symbol" error={profileErrors.password} />
                    <PasswordStrengthChecker password={profile.password} />
                  </div>
                  <div>
                    <Field label="Confirm New Password" type="password"
                      value={profile.password_confirmation} onChange={setP('password_confirmation')}
                      placeholder="Repeat password" error={profileErrors.password_confirmation} />
                    <PasswordStrengthChecker
                      password={profile.password}
                      confirmPassword={profile.password_confirmation}
                      showConfirmMatch
                    />
                  </div>
                </div>
              </div>

              <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1.25rem' }} className="space-y-3">
                <p className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Address</p>
                <Field label="Address Line"
                  value={profile.address} onChange={setP('address')}
                  placeholder="10 Construction Way" />
                <div className="grid grid-cols-2 gap-4">
                  <Field label="City"
                    value={profile.city} onChange={setP('city')}
                    placeholder="London" />
                  <Field label="Province / State"
                    value={profile.province} onChange={setP('province')}
                    placeholder="England" />
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <Field label="Postal Code"
                    value={profile.postal_code} onChange={setP('postal_code')}
                    placeholder="EC1A 1BB" />
                  <Field label="Country"
                    value={profile.country} onChange={setP('country')}
                    placeholder="United Kingdom" />
                </div>
              </div>
            </div>
          )}

          {/* Step 2: Company */}
          {step === 2 && (
            <div className="space-y-5">
              <div className="mb-2">
                <h2 className="text-xl font-semibold" style={{ color: 'var(--text-primary)' }}>Company Details</h2>
                <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>
                  Your registered company identity and location
                </p>
              </div>

              <div className="space-y-4">
                <p className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Company Identity</p>
                <Field label="Registered Company Name" required
                  value={company.name} onChange={setO('name')}
                  placeholder="Acme Construction Ltd" error={companyErrors.name} />
                <div className="grid grid-cols-2 gap-4">
                  <Field label="Company Number"
                    value={company.acn} onChange={setO('acn')} placeholder="12345678" />
                  <Field label="VAT / Tax Number"
                    value={company.abn} onChange={setO('abn')} placeholder="GB123456789" />
                </div>
              </div>

              <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1.25rem' }} className="space-y-4">
                <p className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Contact & Online</p>
                <div className="grid grid-cols-2 gap-4">
                  <Field label="Company Email" type="email"
                    value={company.email} onChange={setO('email')}
                    placeholder="info@acme.com" error={companyErrors.email} />
                  <Field label="Phone"
                    value={company.phone} onChange={setO('phone')} placeholder="+44 20 7946 0000" />
                </div>
                <Field label="Website" type="url"
                  value={company.website} onChange={setO('website')}
                  placeholder="https://acme.com" error={companyErrors.website} />
              </div>

              <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1.25rem' }} className="space-y-3">
                <p className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Registered Address</p>
                <Field label="Street Address" value={company.address} onChange={setO('address')} placeholder="10 Construction Way" />
                <div className="grid grid-cols-3 gap-3">
                  <Field label="City" value={company.city} onChange={setO('city')} placeholder="London" />
                  <Field label="State / County" value={company.state} onChange={setO('state')} placeholder="England" />
                  <Field label="Postcode" value={company.postcode} onChange={setO('postcode')} placeholder="EC1A 1BB" />
                </div>
                <Field label="Country" value={company.country} onChange={setO('country')} placeholder="United Kingdom" />
              </div>
            </div>
          )}

          {/* Step 3: Branding */}
          {step === 3 && (
            <div className="space-y-6">
              <div className="mb-2">
                <h2 className="text-xl font-semibold" style={{ color: 'var(--text-primary)' }}>Company Branding</h2>
                <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>
                  Saved to the database and tied to your company. Each client only sees their own branding.
                </p>
              </div>

              <div>
                <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
                  Company Accent Colour
                </label>
                <p className="text-xs mb-3" style={{ color: 'var(--text-muted)' }}>
                  Used for buttons, highlights and key UI elements. Stored per-company in the database.
                </p>
                <div className="flex items-center gap-3">
                  <input
                    type="color"
                    value={branding.primaryColor}
                    onChange={e => setBranding(b => ({ ...b, primaryColor: e.target.value }))}
                    className="w-10 h-10 rounded-lg cursor-pointer p-0.5"
                    style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}
                  />
                  <div className="flex-1 h-10 rounded-lg flex items-center justify-center text-xs font-semibold"
                       style={{ backgroundColor: branding.primaryColor, color: isLight(branding.primaryColor) ? '#0a0a0a' : '#ffffff' }}>
                    {branding.primaryColor.toUpperCase()} Preview
                  </div>
                </div>
              </div>

              <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1.25rem' }}>
                <ImageUploadCard
                  label="Company Logo"
                  description="Appears in the sidebar and on documents. PNG, JPG or SVG, max 2MB."
                  preview={branding.logoPreview}
                  onFile={(f, url) => setBranding(b => ({ ...b, logoFile: f, logoPreview: url }))}
                  onClear={() => setBranding(b => ({ ...b, logoFile: null, logoPreview: null }))}
                />
              </div>

              <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1.25rem' }}>
                <p className="text-xs font-semibold mb-1" style={{ color: 'var(--text-secondary)' }}>Letterhead Templates</p>
                <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
                  Header and footer images used on formal documents, letters and notices.
                </p>
                <div className="space-y-4">
                  <ImageUploadCard
                    label="Letterhead Header"
                    description="Company name, logo, contact details (recommended: 2480×300px)"
                    preview={branding.headerPreview} aspect="wide"
                    onFile={(f, url) => setBranding(b => ({ ...b, headerFile: f, headerPreview: url }))}
                    onClear={() => setBranding(b => ({ ...b, headerFile: null, headerPreview: null }))}
                  />
                  <ImageUploadCard
                    label="Letterhead Footer"
                    description="Registration, address, legal disclaimer (recommended: 2480×200px)"
                    preview={branding.footerPreview} aspect="wide"
                    onFile={(f, url) => setBranding(b => ({ ...b, footerFile: f, footerPreview: url }))}
                    onClear={() => setBranding(b => ({ ...b, footerFile: null, footerPreview: null }))}
                  />
                </div>
              </div>
            </div>
          )}

          {/* Global error */}
          {globalError && (
            <div className="mt-5 flex items-start gap-2.5 px-4 py-3 rounded-lg text-sm"
                 style={{ backgroundColor: 'rgba(239,68,68,0.08)', color: '#ef4444', border: '1px solid rgba(239,68,68,0.2)' }}>
              <AlertCircle size={16} className="flex-shrink-0 mt-0.5" />
              {globalError}
            </div>
          )}

          {/* Navigation */}
          <div className="flex items-center justify-between mt-8">
            {step > 1 ? (
              <button
                onClick={() => { setGlobalError(null); setStep(s => s - 1); }}
                className="flex items-center gap-1.5 px-4 py-2.5 rounded-lg text-sm font-medium"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
              >
                <ArrowLeft size={14} /> Back
              </button>
            ) : <div />}

            {step === 1 && (
              <button onClick={handleStep1} disabled={saving}
                className="flex items-center gap-1.5 px-6 py-2.5 rounded-lg text-sm font-semibold disabled:opacity-50 active:scale-[0.98]"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
                {saving ? 'Saving…' : <> Continue <ArrowRight size={14} /></>}
              </button>
            )}
            {step === 2 && (
              <button onClick={handleStep2} disabled={saving}
                className="flex items-center gap-1.5 px-6 py-2.5 rounded-lg text-sm font-semibold disabled:opacity-50 active:scale-[0.98]"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
                {saving ? 'Saving…' : <> Continue <ArrowRight size={14} /></>}
              </button>
            )}
            {step === 3 && (
              <button onClick={handleFinalize} disabled={saving}
                className="flex items-center gap-1.5 px-6 py-2.5 rounded-lg text-sm font-semibold disabled:opacity-60 active:scale-[0.98]"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
                {saving ? 'Setting up…' : <> Get Started <ArrowRight size={14} /></>}
              </button>
            )}
          </div>
        </Card>

        <p className="text-center text-xs mt-4" style={{ color: 'var(--text-muted)' }}>
          You can update all of this later in Settings
        </p>
      </div>
    </div>
  );
}
