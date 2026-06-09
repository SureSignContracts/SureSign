'use client';

import { Check, X } from 'lucide-react';

export interface PasswordRules {
  minLength: boolean;
  hasUppercase: boolean;
  hasLowercase: boolean;
  hasNumber: boolean;
  hasSpecial: boolean;
}

export function checkPassword(password: string): PasswordRules {
  return {
    minLength:    password.length >= 8,
    hasUppercase: /[A-Z]/.test(password),
    hasLowercase: /[a-z]/.test(password),
    hasNumber:    /[0-9]/.test(password),
    hasSpecial:   /[^A-Za-z0-9]/.test(password),
  };
}

export function isPasswordValid(rules: PasswordRules): boolean {
  return Object.values(rules).every(Boolean);
}

const RULES: { key: keyof PasswordRules; label: string }[] = [
  { key: 'minLength',    label: 'At least 8 characters' },
  { key: 'hasUppercase', label: 'One uppercase letter' },
  { key: 'hasLowercase', label: 'One lowercase letter' },
  { key: 'hasNumber',    label: 'One number' },
  { key: 'hasSpecial',   label: 'One special character (!@#$%…)' },
];

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
      {RULES.map(({ key, label }) => {
        const met = rules[key];
        return (
          <div key={key} className="flex items-center gap-2">
            <span
              className="flex-shrink-0 w-4 h-4 rounded-full flex items-center justify-center"
              style={{ backgroundColor: met ? 'rgba(34,197,94,0.15)' : 'rgba(239,68,68,0.1)' }}
            >
              {met
                ? <Check size={9} style={{ color: '#22c55e' }} strokeWidth={3} />
                : <X size={9} style={{ color: '#ef4444' }} strokeWidth={3} />
              }
            </span>
            <span className="text-xs" style={{ color: met ? '#22c55e' : 'var(--text-muted)' }}>
              {label}
            </span>
          </div>
        );
      })}

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
