'use client';

import { useState, useEffect, useRef } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Settings, Save, Check, Upload, X, Palette, Building2 } from 'lucide-react';
import api from '@/lib/api';

type Tab = 'branding' | 'information';

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
}

function Field({
  label, value, onChange, placeholder, type = 'text', textarea,
}: {
  label: string; value: string; onChange: (v: string) => void;
  placeholder?: string; type?: string; textarea?: boolean;
}) {
  const cls = "w-full px-3 py-2.5 rounded-lg text-sm outline-none resize-none";
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
        className={`relative rounded-xl overflow-hidden flex items-center justify-center cursor-pointer border-2 border-dashed transition-all ${aspect === 'wide' ? 'h-24 w-full' : 'w-28 h-28'}`}
        style={{ borderColor: preview ? 'transparent' : 'var(--border)', backgroundColor: 'var(--bg-elevated)' }}
        onClick={() => ref.current?.click()}
      >
        {preview ? (
          <img src={preview} alt={label} className="w-full h-full object-contain" />
        ) : (
          <div className="flex flex-col items-center gap-1.5 p-3">
            <Upload size={18} style={{ color: 'var(--text-muted)' }} />
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
    contact_email: '', contact_phone: '', website: '', address: '', city: '', state: '', postcode: '', country: '', vat_number: '',
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
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['branding'] });
      setSaved(true);
      setTimeout(() => setSaved(false), 2500);
    },
  });

  const handleSave = () => {
    if (tab === 'branding') brandMutation.mutate(brandForm);
    else infoMutation.mutate(infoForm);
  };

  const isPending = brandMutation.isPending || infoMutation.isPending;

  const tabs: { id: Tab; label: string; icon: any }[] = [
    { id: 'branding',     label: 'Company Branding',    icon: Palette },
    { id: 'information',  label: 'Company Information',  icon: Building2 },
  ];

  return (
    <div className="p-6 max-w-3xl mx-auto">
      <div className="flex items-center gap-3 mb-6">
        <div className="w-9 h-9 rounded-xl flex items-center justify-center" style={{ backgroundColor: 'var(--bg-elevated)' }}>
          <Settings size={18} style={{ color: 'var(--text-secondary)' }} />
        </div>
        <div>
          <h1 className="text-2xl font-semibold" style={{ color: 'var(--text-primary)' }}>Settings</h1>
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Manage your organization preferences</p>
        </div>
      </div>

      {/* Tab bar */}
      <div className="flex gap-1 p-1 rounded-lg mb-6 w-fit" style={{ backgroundColor: 'var(--bg-elevated)' }}>
        {tabs.map(t => (
          <button key={t.id} onClick={() => { setTab(t.id); setSaved(false); }}
            className="flex items-center gap-1.5 px-4 py-2 rounded-md text-sm font-medium transition-all"
            style={tab === t.id
              ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
              : { color: 'var(--text-secondary)' }
            }
          >
            <t.icon size={14} />
            {t.label}
          </button>
        ))}
      </div>

      <div className="rounded-2xl p-6" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
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
        ) : (
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
          </div>
        )}

        {/* Save button */}
        <div className="mt-6 flex justify-end">
          <button
            onClick={handleSave}
            disabled={isPending}
            className="flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium transition-opacity disabled:opacity-60"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            {saved ? <Check size={15} /> : <Save size={15} />}
            {saved ? 'Saved!' : isPending ? 'Saving…' : 'Save Changes'}
          </button>
        </div>
      </div>
    </div>
  );
}

