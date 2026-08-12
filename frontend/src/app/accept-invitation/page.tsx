'use client';

import { useEffect, useState, Suspense } from 'react';
import { useSearchParams } from 'next/navigation';
import { ArrowRight, Eye, EyeOff, MailCheck, XCircle, CheckCircle2 } from 'lucide-react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import PasswordStrengthChecker, { checkPassword, isPasswordValid } from '@/components/ui/PasswordStrengthChecker';

const EASE = 'cubic-bezier(0.32, 0.72, 0, 1)';
const MISSING_INFO_MESSAGE = 'This invitation link is missing required information.';

type InvitationDetails = {
  already_accepted: boolean;
  email?: string;
  first_name?: string | null;
  organization_name?: string | null;
  expiry_days?: number;
};

function AcceptInvitationContent() {
  const searchParams = useSearchParams();
  const userId = searchParams.get('user') || '';
  const expires = searchParams.get('expires') || '';
  const signature = searchParams.get('signature') || '';
  const hasParams = !!userId && !!expires && !!signature;

  // The query string forwarded to the backend must be exactly what
  // InvitationLinkService signed — expires/signature only, nothing else —
  // or Laravel's signature check fails.
  const query = { expires, signature };
  const apiPath = `/public/invitations/${userId}`;

  const [status, setStatus] = useState<'loading' | 'form' | 'already_accepted' | 'invalid' | 'success'>(
    hasParams ? 'loading' : 'invalid',
  );
  const [loadError, setLoadError] = useState(hasParams ? '' : MISSING_INFO_MESSAGE);
  const [details, setDetails] = useState<InvitationDetails | null>(null);

  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [showPw, setShowPw] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState('');

  useEffect(() => {
    if (!hasParams) return;

    api.get(apiPath, { params: query })
      .then((res) => {
        const data: InvitationDetails = res.data.data;
        setDetails(data);
        setStatus(data.already_accepted ? 'already_accepted' : 'form');
      })
      .catch((err) => {
        setStatus('invalid');
        setLoadError(getErrorMessage(err, 'This SureSign invitation is no longer valid.'));
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [hasParams, userId, expires, signature]);

  async function handleSubmit(e: React.SyntheticEvent<HTMLFormElement>) {
    e.preventDefault();
    setSubmitError('');

    if (!isPasswordValid(checkPassword(password))) {
      setSubmitError('Please choose a password that meets all the requirements below.');
      return;
    }
    if (password !== passwordConfirmation) {
      setSubmitError('Passwords do not match.');
      return;
    }

    setSubmitting(true);
    try {
      await api.post(apiPath, { password, password_confirmation: passwordConfirmation }, { params: query });
      setStatus('success');
    } catch (err) {
      const code = (err as { response?: { data?: { code?: string } } })?.response?.data?.code;
      if (code === 'invitation_already_accepted') {
        setStatus('already_accepted');
      } else {
        setSubmitError(getErrorMessage(err, 'Something went wrong setting up your account. Please try again.'));
      }
    } finally {
      setSubmitting(false);
    }
  }

  // Genuine stored name only, never derived from the email address. See
  // InvitationEmailService for the same rule applied to the email itself.
  const greeting = details?.first_name ? `Hi ${details.first_name}. ` : 'Hi. ';
  const joinLine = details?.organization_name
    ? `You've been invited to join ${details.organization_name} on SureSign.`
    : "You've been invited to join SureSign.";

  return (
    <div className="min-h-dvh flex items-center justify-center px-6" style={{ backgroundColor: '#ffffff' }}>
      <div className="w-full max-w-[380px] space-y-6">
        {status === 'loading' && (
          <div className="text-center space-y-4">
            <div className="w-11 h-11 mx-auto rounded-xl flex items-center justify-center" style={{ backgroundColor: '#0f0f0f' }}>
              <MailCheck size={18} strokeWidth={1.75} color="#ffffff" />
            </div>
            <p className="text-sm" style={{ color: '#737373' }}>Loading your invitation…</p>
          </div>
        )}

        {status === 'invalid' && (
          <div className="text-center space-y-4">
            <div className="w-11 h-11 mx-auto rounded-xl flex items-center justify-center" style={{ backgroundColor: '#fef2f2' }}>
              <XCircle size={18} strokeWidth={1.75} color="#dc2626" />
            </div>
            <h2 className="text-[1.4rem] font-semibold" style={{ color: '#0f0f0f', letterSpacing: '-0.025em' }}>
              Invitation expired
            </h2>
            <p className="text-sm" style={{ color: '#737373' }}>{loadError || 'This SureSign invitation is no longer valid.'}</p>
            <a href="/login" className="text-sm font-medium hover:underline" style={{ color: '#0f0f0f' }}>
              Back to sign in
            </a>
          </div>
        )}

        {status === 'already_accepted' && (
          <div className="text-center space-y-4">
            <div className="w-11 h-11 mx-auto rounded-xl flex items-center justify-center" style={{ backgroundColor: '#0f0f0f' }}>
              <CheckCircle2 size={18} strokeWidth={1.75} color="#ffffff" />
            </div>
            <h2 className="text-[1.4rem] font-semibold" style={{ color: '#0f0f0f', letterSpacing: '-0.025em' }}>
              Invitation already accepted
            </h2>
            <p className="text-sm" style={{ color: '#737373' }}>This SureSign account has already been set up.</p>
            <a
              href="/login"
              className="inline-flex items-center justify-center rounded-full px-6 py-3 text-sm font-medium hover:bg-[#262626]"
              style={{ backgroundColor: '#0f0f0f', color: '#ffffff' }}
            >
              Go to Login
            </a>
          </div>
        )}

        {status === 'success' && (
          <div className="text-center space-y-4">
            <div className="w-11 h-11 mx-auto rounded-xl flex items-center justify-center" style={{ backgroundColor: '#0f0f0f' }}>
              <CheckCircle2 size={18} strokeWidth={1.75} color="#ffffff" />
            </div>
            <h2 className="text-[1.4rem] font-semibold" style={{ color: '#0f0f0f', letterSpacing: '-0.025em' }}>
              Your SureSign account is ready
            </h2>
            <p className="text-sm" style={{ color: '#737373' }}>Your account has been set up successfully.</p>
            <a
              href="/login"
              className="inline-flex items-center justify-center rounded-full px-6 py-3 text-sm font-medium hover:bg-[#262626]"
              style={{ backgroundColor: '#0f0f0f', color: '#ffffff' }}
            >
              Continue to SureSign
            </a>
          </div>
        )}

        {status === 'form' && (
          <>
            <div>
              <div className="w-11 h-11 rounded-xl flex items-center justify-center mb-6" style={{ backgroundColor: '#0f0f0f' }}>
                <MailCheck size={18} strokeWidth={1.75} color="#ffffff" />
              </div>
              <h2 className="text-[1.7rem] font-semibold" style={{ color: '#0f0f0f', letterSpacing: '-0.025em' }}>
                Set up your SureSign account
              </h2>
              <p className="mt-1.5 text-sm" style={{ color: '#737373' }}>
                {greeting}{joinLine} Create a password to finish accepting your invitation.
              </p>
              {details?.email && (
                <p className="mt-2 text-xs" style={{ color: '#a3a3a3' }}>{details.email}</p>
              )}
            </div>

            {submitError && (
              <div
                className="rounded-xl px-4 py-3 text-sm"
                style={{ backgroundColor: 'rgba(220,38,38,0.06)', border: '1px solid rgba(220,38,38,0.18)', color: '#b91c1c' }}
              >
                {submitError}
              </div>
            )}

            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="space-y-1.5">
                <label className="block text-xs font-medium" style={{ color: '#525252' }}>Password</label>
                <div className="relative">
                  <input
                    type={showPw ? 'text' : 'password'}
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    required
                    autoComplete="new-password"
                    placeholder="••••••••"
                    className="w-full px-4 py-3 pr-11 rounded-xl text-sm bg-[#f7f7f7] border border-[#e5e5e5] focus:border-[#0f0f0f] focus:bg-white focus-visible:outline-2 focus-visible:outline-[#0f0f0f] focus-visible:outline-offset-2"
                    style={{ color: '#0f0f0f', transition: `border-color 300ms ${EASE}, background-color 300ms ${EASE}` }}
                  />
                  <button
                    type="button"
                    tabIndex={-1}
                    onClick={() => setShowPw(p => !p)}
                    className="absolute right-3.5 top-1/2 -translate-y-1/2 rounded transition-opacity hover:opacity-60"
                    style={{ color: '#a3a3a3' }}
                  >
                    {showPw ? <EyeOff size={15} strokeWidth={1.75} /> : <Eye size={15} strokeWidth={1.75} />}
                  </button>
                </div>
              </div>

              <div className="space-y-1.5">
                <label className="block text-xs font-medium" style={{ color: '#525252' }}>Confirm password</label>
                <input
                  type={showPw ? 'text' : 'password'}
                  value={passwordConfirmation}
                  onChange={(e) => setPasswordConfirmation(e.target.value)}
                  required
                  autoComplete="new-password"
                  placeholder="••••••••"
                  className="w-full px-4 py-3 rounded-xl text-sm bg-[#f7f7f7] border border-[#e5e5e5] focus:border-[#0f0f0f] focus:bg-white focus-visible:outline-2 focus-visible:outline-[#0f0f0f] focus-visible:outline-offset-2"
                  style={{ color: '#0f0f0f', transition: `border-color 300ms ${EASE}, background-color 300ms ${EASE}` }}
                />
              </div>

              <PasswordStrengthChecker password={password} confirmPassword={passwordConfirmation} showConfirmMatch />

              <button
                type="submit"
                disabled={submitting}
                className="group relative w-full flex items-center justify-center rounded-full py-3 pl-6 pr-12 text-sm font-medium hover:bg-[#262626] active:scale-[0.98] disabled:opacity-40 disabled:cursor-not-allowed"
                style={{
                  backgroundColor: '#0f0f0f',
                  color: '#ffffff',
                  transition: `background-color 300ms ${EASE}, transform 200ms ${EASE}, opacity 200ms ${EASE}`,
                }}
              >
                {submitting ? 'Setting up…' : 'Accept Invitation & Set Up Account'}
                <span
                  className="absolute right-1.5 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full flex items-center justify-center group-hover:translate-x-0.5"
                  style={{ backgroundColor: 'rgba(255,255,255,0.12)', transition: `transform 300ms ${EASE}` }}
                >
                  <ArrowRight size={13} strokeWidth={2} />
                </span>
              </button>
            </form>
          </>
        )}
      </div>
    </div>
  );
}

export default function AcceptInvitationPage() {
  return (
    <Suspense fallback={null}>
      <AcceptInvitationContent />
    </Suspense>
  );
}
