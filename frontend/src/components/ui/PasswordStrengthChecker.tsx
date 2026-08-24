'use client';

import { useEffect, useRef } from 'react';
import { Check, Circle, X } from 'lucide-react';
import { gsap } from 'gsap';
import { prefersReducedMotion, GSAP_EASE_OUT } from '@/lib/motion';

/**
 * Password Composition Restoration (August 24, 2026) — SureSign's product
 * password policy was intentionally changed on this date to require one
 * uppercase letter, one lowercase letter, one number, and one special
 * character, in addition to the pre-existing minimum-length and
 * uncompromised-password check. This is a deliberate reversal of the
 * earlier "composition not mandatory" decision (see the backend's
 * `App\Support\Auth\SureSignPasswordPolicy` docblock for the full history)
 * — not a correction of a defect in that earlier policy. The minimum
 * length itself was originally 15 characters, then intentionally lowered
 * to 12 (also August 24, 2026) — see `SureSignPasswordPolicy::MIN_LENGTH`,
 * the single source of truth this component's `minLength` check mirrors.
 *
 * This component is UX guidance only; the backend
 * (`SureSignPasswordPolicy::rules()`) is authoritative. Character-Class
 * Consistency Correction (August 24, 2026) — `uppercase`/`lowercase`/
 * `number`/`special` all use the same Unicode property escapes as the
 * backend's own rules (`\p{Lu}`/`\p{Ll}`/`\p{N}`/`\p{S}|\p{P}`), not an
 * ASCII-only or negation-based approximation. An earlier version used
 * `[A-Z]`/`[a-z]`/`[0-9]`/`[^A-Za-z0-9\s]` — the negation form specifically
 * misclassified any non-ASCII LETTER (e.g. `é`) as a "special character",
 * which the backend correctly rejects as neither a symbol nor punctuation;
 * a password this checker marked fully valid could then fail backend
 * validation. `special` mirrors `PasswordHasSpecialCharacter` exactly —
 * whitespace alone never satisfies it (Laravel's own `Password::symbols()`
 * would otherwise let a bare space count via its `\p{Z}` branch, which is
 * not SureSign's product definition of "special character").
 *
 * Deliberately does NOT claim "Not compromised" — compromise status is
 * only known after the backend's `Password::defaults()->uncompromised()`
 * check runs; a client-side component has no way to know that safely (and
 * never should — the plaintext password never needs to leave the browser
 * for this component's checks to work, but the breach CHECK ITSELF only
 * happens server-side). Likewise never surfaces the 64-character maximum
 * or the 72-byte bcrypt safety boundary here — those remain backend
 * implementation details, surfaced only via ordinary form error handling
 * if a submission is ever rejected for them.
 */
export interface PasswordRules {
  minLength: boolean;
  uppercase: boolean;
  lowercase: boolean;
  number: boolean;
  special: boolean;
}

interface Requirement {
  key: keyof PasswordRules;
  label: string;
  test: (password: string) => boolean;
}

// Character-Class Consistency Correction (August 24, 2026) — these were
// previously ASCII-only ([A-Z]/[a-z]/[0-9]) plus a NEGATION-based special-
// character check ([^A-Za-z0-9\s]), which is not semantically equivalent
// to the backend's Unicode-aware rules
// (Illuminate\Validation\Rules\Password::mixedCase()/numbers() use
// \p{Lu}/\p{Ll}/\pN; App\Rules\PasswordHasSpecialCharacter uses
// \p{S}|\p{P}). The negation form specifically misclassified any non-ASCII
// LETTER (e.g. 'é') as a "special character", which the backend correctly
// rejects as neither a symbol nor punctuation — a password the frontend
// marked fully valid could then fail backend validation. Now using the
// same Unicode property escapes (with the `u` flag) as the backend's own
// character classes, so a password this checker marks satisfied cannot
// disagree with SureSignPasswordPolicy for these four rules.
const REQUIREMENTS: Requirement[] = [
  { key: 'minLength', label: '12+ characters', test: p => p.length >= 12 },
  { key: 'uppercase', label: 'Uppercase letter', test: p => /\p{Lu}/u.test(p) },
  { key: 'lowercase', label: 'Lowercase letter', test: p => /\p{Ll}/u.test(p) },
  { key: 'number', label: 'Number', test: p => /\p{N}/u.test(p) },
  // Mirrors App\Rules\PasswordHasSpecialCharacter exactly — Laravel's own
  // Password::symbols() also matches \p{Z} (Unicode Separator, which
  // includes plain whitespace), so a bare space would satisfy it; this
  // deliberately omits \p{Z} so whitespace alone never counts as special.
  { key: 'special', label: 'Special character', test: p => /\p{S}|\p{P}/u.test(p) },
];

export function checkPassword(password: string): PasswordRules {
  const rules = {} as PasswordRules;
  for (const requirement of REQUIREMENTS) {
    rules[requirement.key] = requirement.test(password);
  }
  return rules;
}

export function isPasswordValid(rules: PasswordRules): boolean {
  return Object.values(rules).every(Boolean);
}

/**
 * A single requirement in the compact checklist. Its own small GSAP
 * transition fires only when ITS satisfied state actually changes, never on
 * every keystroke.
 */
function RequirementChip({ label, satisfied }: { label: string; satisfied: boolean }) {
  const iconRef = useRef<HTMLSpanElement>(null);
  const prevSatisfied = useRef(satisfied);
  const isFirstRender = useRef(true);

  useEffect(() => {
    const icon = iconRef.current;
    if (!icon) return;

    if (isFirstRender.current) {
      // The shared entrance timeline (see PasswordStrengthChecker below)
      // already reveals this chip on mount — this effect only handles
      // LATER state changes, so it must not also animate on first paint.
      isFirstRender.current = false;
      prevSatisfied.current = satisfied;
      return;
    }

    if (prevSatisfied.current === satisfied) return;
    prevSatisfied.current = satisfied;

    if (prefersReducedMotion()) {
      gsap.set(icon, { scale: 1, opacity: 1 });
      return;
    }

    gsap.fromTo(icon, { scale: 0.9, opacity: 0.4 }, { scale: 1, opacity: 1, duration: 0.18, ease: 'power2.out' });
  }, [satisfied]);

  return (
    <span className="flex min-w-0 items-center gap-2">
      <span
        ref={iconRef}
        className="flex size-4 flex-shrink-0 items-center justify-center rounded-full"
        style={{
          backgroundColor: satisfied ? '#16a34a' : 'transparent',
          border: satisfied ? '1px solid #16a34a' : '1px solid var(--border-light)',
        }}
        aria-hidden="true"
      >
        {satisfied
          ? <Check size={10} style={{ color: '#fff' }} strokeWidth={3} />
          : <Circle size={3} style={{ color: 'var(--text-muted)' }} fill="var(--text-muted)" strokeWidth={0} />
        }
      </span>
      <span className="truncate text-[11px] font-medium" style={{ color: satisfied ? 'var(--text-primary)' : 'var(--text-secondary)' }}>
        {label}
        <span className="sr-only">{satisfied ? ' — met' : ' — not yet met'}</span>
      </span>
    </span>
  );
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
  const rules = checkPassword(password);
  const allSatisfied = isPasswordValid(rules);
  const satisfiedCount = Object.values(rules).filter(Boolean).length;
  const isVisible = password.length > 0;
  const showMatch = showConfirmMatch && typeof confirmPassword === 'string' && confirmPassword.length > 0;
  const passwordsMatch = password === confirmPassword;

  const containerRef = useRef<HTMLDivElement>(null);
  const chipsRef = useRef<HTMLDivElement>(null);

  // Run the entrance when the panel actually changes from hidden to visible.
  // The checker itself can be mounted while the password is still empty, so
  // a mount-only effect would fire before these refs exist and show no motion.
  useEffect(() => {
    if (!isVisible) return;

    const container = containerRef.current;
    const chipsContainer = chipsRef.current;
    if (!container || !chipsContainer) return;
    const chips = Array.from(chipsContainer.children) as HTMLElement[];

    if (prefersReducedMotion()) {
      gsap.set(container, { opacity: 1, y: 0 });
      gsap.set(chips, { opacity: 1, y: 0 });
      return;
    }

    const tl = gsap.timeline();
    tl.fromTo(
      container,
      { autoAlpha: 0, y: -6, scale: 0.985 },
      { autoAlpha: 1, y: 0, scale: 1, duration: 0.28, ease: GSAP_EASE_OUT },
    ).fromTo(
      chips,
      { autoAlpha: 0, y: 4 },
      { autoAlpha: 1, y: 0, duration: 0.2, ease: GSAP_EASE_OUT, stagger: 0.035 },
      '-=0.14',
    );

    return () => { tl.kill(); };
  }, [isVisible]);

  // Lightweight helper text, not a permanent page fixture — appears once
  // the user actually starts typing a password, same as before this
  // component existed (a plain hint sentence in the same spot).
  if (!isVisible) return null;

  return (
    <div
      ref={containerRef}
      className="mt-2.5 overflow-hidden rounded-xl"
      style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}
    >
      <div className="px-3.5 pb-3 pt-3">
        <div className="mb-2.5 flex items-center justify-between gap-3">
          <p className="text-[11px] font-semibold" style={{ color: 'var(--text-primary)' }}>
            Password requirements
          </p>
          <span className="text-[10px] font-medium tabular-nums" style={{ color: allSatisfied ? '#16a34a' : 'var(--text-muted)' }}>
            {allSatisfied ? 'Ready' : `${satisfiedCount} of ${REQUIREMENTS.length}`}
          </span>
        </div>

        <div className="mb-3 grid grid-cols-5 gap-1" aria-hidden="true">
          {REQUIREMENTS.map((requirement, index) => (
            <span
              key={requirement.key}
              className="h-0.5 rounded-full transition-colors duration-200"
              style={{ backgroundColor: index < satisfiedCount ? '#16a34a' : 'var(--border-light)' }}
            />
          ))}
        </div>

        <div ref={chipsRef} className="grid grid-cols-2 gap-x-3 gap-y-2">
          {REQUIREMENTS.map((requirement, index) => (
            <span key={requirement.key} className={index === REQUIREMENTS.length - 1 ? 'col-span-2' : undefined}>
              <RequirementChip label={requirement.label} satisfied={rules[requirement.key]} />
            </span>
          ))}
        </div>
      </div>

      {showMatch && (
        <div className="flex items-center gap-2 px-3.5 py-2.5" style={{ borderTop: '1px solid var(--border)' }}>
          {passwordsMatch
            ? <Check size={13} style={{ color: '#16a34a' }} strokeWidth={3} />
            : <X size={13} style={{ color: '#dc2626' }} strokeWidth={3} />
          }
          <span className="text-[11px] font-medium" style={{ color: passwordsMatch ? '#16a34a' : '#dc2626' }}>
            {passwordsMatch ? 'Passwords match' : 'Passwords do not match'}
          </span>
        </div>
      )}

      <span className="sr-only" aria-live="polite">
        {allSatisfied ? 'All password requirements met.' : ''}
      </span>
    </div>
  );
}
