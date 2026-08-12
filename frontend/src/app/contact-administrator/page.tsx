'use client';

import { useEffect, useState } from 'react';
import { ArrowLeft, Mail, ShieldQuestion, UserPlus, KeyRound } from 'lucide-react';
import api from '@/lib/api';

const EASE = 'cubic-bezier(0.32, 0.72, 0, 1)';

const REASONS = [
  {
    icon: UserPlus,
    title: "You haven't been invited yet",
    body: 'Your organisation\'s Super Admin or Admin can send you a SureSign invitation from the Users page.',
  },
  {
    icon: KeyRound,
    title: 'You’ve forgotten your password',
    body: 'Use Forgot password on the sign-in page first. Most password issues are resolved there without contacting anyone.',
  },
  {
    icon: ShieldQuestion,
    title: 'Your account is unavailable',
    body: 'A deactivated or banned account can only be restored by your organisation\'s Super Admin.',
  },
];

export default function ContactAdministratorPage() {
  const [supportEmail, setSupportEmail] = useState<string | null>(null);
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [message, setMessage] = useState('');
  // Honeypot — never shown to a real visitor. A bot that fills every field
  // it finds fills this too; a genuine submission always leaves it blank.
  const [website, setWebsite] = useState('');
  const [status, setStatus] = useState<'idle' | 'submitting' | 'sent' | 'error'>('idle');
  const [error, setError] = useState('');

  useEffect(() => {
    api.get('/guest-settings')
      .then(r => { if (r.data?.data?.support_email) setSupportEmail(r.data.data.support_email); })
      .catch(() => {});
  }, []);

  async function handleSubmit(e: React.SyntheticEvent<HTMLFormElement>) {
    e.preventDefault();
    setStatus('submitting');
    setError('');
    try {
      await api.post('/account-access-enquiry', { name: name || undefined, email, message, website });
      setStatus('sent');
    } catch (err) {
      setStatus('error');
      const data = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
      setError(data?.errors?.email?.[0] ?? data?.errors?.message?.[0] ?? data?.message ?? 'Something went wrong sending your message. Please try again.');
    }
  }

  return (
    <div className="min-h-dvh px-4 py-5 sm:px-8 sm:py-8" style={{ backgroundColor: '#f3f2ef' }}>
      <div className="mx-auto flex min-h-[calc(100dvh-2.5rem)] w-full max-w-[1080px] flex-col sm:min-h-[calc(100dvh-4rem)]">
        <header className="ss-access-reveal mb-5 flex items-center justify-between" style={{ animationDelay: '60ms' }}>
          <a href="/login" className="group inline-flex items-center gap-2 rounded-md text-xs font-semibold hover:opacity-60" style={{ color: '#343330' }}>
            <ArrowLeft size={14} strokeWidth={1.75} className="transition-transform duration-200 group-hover:-translate-x-0.5" />
            Back to sign in
          </a>
          <div className="flex items-center gap-2.5">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src="/logo_black/SureSign_BLOGO.webp" alt="SureSign" className="h-6 w-6 object-contain" />
            <span className="text-sm font-semibold tracking-tight" style={{ color: '#171715' }}>SureSign</span>
          </div>
        </header>

        <main
          className="ss-access-shell grid flex-1 overflow-hidden rounded-[1.4rem] border lg:grid-cols-[0.9fr_1.1fr]"
          style={{ borderColor: '#dedcd6', backgroundColor: '#ffffff', boxShadow: '0 18px 54px rgba(28,27,24,0.08)' }}
        >
          <section className="ss-access-panel-left relative overflow-hidden px-6 py-8 sm:px-9 sm:py-10 lg:px-11 lg:py-12" style={{ backgroundColor: '#10100f' }}>
            <div
              className="pointer-events-none absolute inset-0"
              aria-hidden="true"
              style={{
                backgroundImage: 'linear-gradient(rgba(255,255,255,0.035) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.035) 1px, transparent 1px)',
                backgroundSize: '48px 48px',
                maskImage: 'radial-gradient(circle at 20% 15%, black, transparent 72%)',
                WebkitMaskImage: 'radial-gradient(circle at 20% 15%, black, transparent 72%)',
              }}
            />

            <div className="relative z-10">
              <div className="ss-access-reveal mb-8 flex h-9 w-9 items-center justify-center rounded-lg border border-white/10 bg-white/[0.06] text-white/70" style={{ animationDelay: '340ms' }}>
                <Mail size={15} strokeWidth={1.7} />
              </div>
              <div className="ss-access-reveal" style={{ animationDelay: '410ms' }}>
                <p className="text-[10px] font-semibold uppercase tracking-[0.2em] text-white/35">Workspace access</p>
                <h1 className="mt-4 max-w-[12ch] text-[2rem] font-semibold leading-[1.05] text-white sm:text-[2.3rem]" style={{ letterSpacing: '-0.04em' }}>
                  Find the right route back in.
                </h1>
                <p className="mt-4 max-w-[35ch] text-sm leading-relaxed text-white/45">
                  SureSign access is managed by your organisation. Start with the option that matches your situation.
                </p>
              </div>

              <div className="mt-9">
                {REASONS.map(({ icon: Icon, title, body }, index) => (
                  <div
                    key={title}
                    className="ss-access-reveal grid grid-cols-[1.8rem_1fr] gap-3 border-t border-white/[0.09] py-4"
                    style={{ animationDelay: `${540 + index * 110}ms` }}
                  >
                    <div className="flex items-start justify-between pt-0.5">
                      <span className="font-mono text-[9px] text-white/25">{String(index + 1).padStart(2, '0')}</span>
                    </div>
                    <div>
                      <div className="flex items-center gap-2">
                        <Icon size={13} strokeWidth={1.65} className="text-white/40" />
                        <h2 className="text-xs font-semibold text-white/75">{title}</h2>
                      </div>
                      <p className="mt-1.5 text-[11px] leading-relaxed text-white/38">{body}</p>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </section>

          <section className="ss-access-panel-right flex items-center px-6 py-8 sm:px-10 sm:py-10 lg:px-12 lg:py-12">
            <div className="w-full max-w-[500px]">
              <div className="ss-access-reveal" style={{ animationDelay: '410ms' }}>
                <p className="text-[10px] font-semibold uppercase tracking-[0.18em]" style={{ color: '#96938b' }}>Still need help?</p>
                <h2 className="mt-3 text-[1.65rem] font-semibold leading-tight" style={{ color: '#11110f', letterSpacing: '-0.035em' }}>
                  Contact SureSign support
                </h2>
                <p className="mt-2 max-w-[46ch] text-sm leading-relaxed" style={{ color: '#6d6b65' }}>
                  If your administrator cannot resolve the issue, send us the details below.
                  {supportEmail ? ` Your message goes to ${supportEmail}.` : ''}
                </p>
              </div>

              {status === 'sent' ? (
                <div role="status" className="ss-access-status mt-7 border-l-2 px-4 py-3 text-sm leading-relaxed" style={{ backgroundColor: '#f0f7f3', borderColor: '#4d8966', color: '#397154' }}>
                  Message sent. We&apos;ll reply to the email address you provided.
                </div>
              ) : (
                <form onSubmit={handleSubmit} className="ss-access-form mt-7 space-y-4">
                  {error && (
                    <p role="alert" className="ss-access-status border-l-2 px-3.5 py-3 text-xs leading-relaxed" style={{ backgroundColor: '#f9eeec', borderColor: '#bb5b50', color: '#96392f' }}>
                      {error}
                    </p>
                  )}

                  {/* Honeypot field — off-screen, never focusable/visible to a real visitor. */}
                  <div className="absolute left-[-9999px]" aria-hidden="true">
                    <label htmlFor="contact-website">Website</label>
                    <input id="contact-website" type="text" tabIndex={-1} autoComplete="off" value={website} onChange={(e) => setWebsite(e.target.value)} />
                  </div>

                  <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <ContactField label="Name" optional>
                      <input id="contact-name" type="text" value={name} onChange={(e) => setName(e.target.value)} autoComplete="name" className="ss-contact-input" />
                    </ContactField>
                    <ContactField label="Your email address">
                      <input id="contact-email" type="email" required value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="email" placeholder="you@company.com" className="ss-contact-input" />
                    </ContactField>
                  </div>

                  <ContactField label="What do you need help with?">
                    <textarea
                      id="contact-message"
                      required
                      minLength={10}
                      rows={4}
                      value={message}
                      onChange={(e) => setMessage(e.target.value)}
                      placeholder="Tell us what happened and which workspace you are trying to access."
                      className="ss-contact-input ss-contact-textarea min-h-[7rem] resize-y"
                    />
                  </ContactField>

                  <div className="flex flex-col gap-3 pt-1 sm:flex-row sm:items-center sm:justify-between">
                    <p className="max-w-[25ch] text-[10px] leading-relaxed" style={{ color: '#99968e' }}>
                      Do not include passwords or invitation links.
                    </p>
                    <button
                      type="submit"
                      disabled={status === 'submitting'}
                      className="group inline-flex h-11 items-center justify-center gap-2 rounded-xl px-5 text-xs font-semibold hover:-translate-y-0.5 hover:bg-[#292926] hover:shadow-[0_8px_18px_rgba(15,15,15,0.16)] active:translate-y-px disabled:cursor-not-allowed disabled:opacity-50 disabled:shadow-none"
                      style={{ backgroundColor: '#0f0f0f', color: '#ffffff', transition: `background-color 200ms ${EASE}, transform 200ms ${EASE}, box-shadow 240ms ${EASE}` }}
                    >
                      <Mail size={13} strokeWidth={1.75} className="transition-transform duration-200 group-hover:-translate-y-0.5" />
                      {status === 'submitting' ? 'Sending…' : 'Send message'}
                    </button>
                  </div>
                </form>
              )}
            </div>
          </section>
        </main>
      </div>
    </div>
  );
}

function ContactField({ label, optional = false, children }: { label: string; optional?: boolean; children: React.ReactNode }) {
  const id = label === 'Name' ? 'contact-name' : label === 'Your email address' ? 'contact-email' : 'contact-message';

  return (
    <div className="space-y-2">
      <label htmlFor={id} className="block text-xs font-semibold" style={{ color: '#44423d' }}>
        {label} {optional && <span className="font-normal" style={{ color: '#9a9790' }}>(optional)</span>}
      </label>
      {children}
    </div>
  );
}
