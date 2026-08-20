'use client';

import { Check, X } from 'lucide-react';

/**
 * Unified Password Security Hardening — this component is UX guidance
 * only; the backend (`App\Support\Auth\SureSignPasswordPolicy`) is
 * authoritative. It no longer encodes the old 8-character/uppercase/
 * lowercase/number/symbol composition rule — a 15+ character passphrase
 * with no uppercase, number, or symbol is fully valid, and this component
 * must never suggest otherwise.
 *
 * Deliberately does NOT claim "Not compromised" — compromise status is
 * only known after the backend's `Password::defaults()->uncompromised()`
 * check runs; a client-side component has no way to know that safely
 * (and never should — see SureSignPasswordPolicy's own docblock on why
 * the plaintext password never needs to leave the browser for this
 * check to work, but the CHECK ITSELF only happens server-side).
 */
export interface PasswordRules {
  minLength: boolean;
}

export function checkPassword(password: string): PasswordRules {
  return {
    minLength: password.length >= 15,
  };
}

export function isPasswordValid(rules: PasswordRules): boolean {
  return Object.values(rules).every(Boolean);
}

export default function PasswordStrengthChecker({
  password,
  confirmPassword,
  showConfirmMatch = false,
}: {
  password: string;
  confirmPassword?: string;
  showConfirmMatch?: boolean;
}) {
  if (!password) return null;

  const rules = checkPassword(password);
  const showMatch = showConfirmMatch && typeof confirmPassword === 'string' && confirmPassword.length > 0;
  const passwordsMatch = password === confirmPassword;

  return (
    <div className="mt-2 space-y-1.5">
      <div className="flex items-center gap-2">
        <span
          className="flex-shrink-0 w-4 h-4 rounded-full flex items-center justify-center"
          style={{ backgroundColor: rules.minLength ? 'rgba(34,197,94,0.15)' : 'rgba(239,68,68,0.1)' }}
        >
          {rules.minLength
            ? <Check size={9} style={{ color: '#22c55e' }} strokeWidth={3} />
            : <X size={9} style={{ color: '#ef4444' }} strokeWidth={3} />
          }
        </span>
        <span className="text-xs" style={{ color: rules.minLength ? '#22c55e' : 'var(--text-muted)' }}>
          At least 15 characters
        </span>
      </div>

      {showMatch && (
        <div className="flex items-center gap-2 pt-0.5">
          <span
            className="flex-shrink-0 w-4 h-4 rounded-full flex items-center justify-center"
            style={{ backgroundColor: passwordsMatch ? 'rgba(34,197,94,0.15)' : 'rgba(239,68,68,0.1)' }}
          >
            {passwordsMatch
              ? <Check size={9} style={{ color: '#22c55e' }} strokeWidth={3} />
              : <X size={9} style={{ color: '#ef4444' }} strokeWidth={3} />
            }
          </span>
          <span className="text-xs" style={{ color: passwordsMatch ? '#22c55e' : '#ef4444' }}>
            {passwordsMatch ? 'Passwords match' : 'Passwords do not match'}
          </span>
        </div>
      )}
    </div>
  );
}
