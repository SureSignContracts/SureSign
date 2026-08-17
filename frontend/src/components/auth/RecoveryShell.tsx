import type { ElementType, ReactNode } from 'react';
import Image from 'next/image';
import { CheckCircle2, FileKey2, ShieldCheck } from 'lucide-react';

export default function RecoveryShell({
  eyebrow,
  title,
  description,
  icon: Icon,
  children,
}: {
  eyebrow: string;
  title: string;
  description: string;
  icon: ElementType;
  children: ReactNode;
}) {
  return (
    <div className="flex min-h-dvh bg-[#f2f2f2] lg:h-dvh lg:overflow-hidden">
      <aside className="relative hidden flex-shrink-0 overflow-hidden bg-[#0a0a0a] p-10 text-white lg:flex lg:w-[48%] lg:flex-col lg:justify-between xl:w-[50%] xl:p-14">
        <div className="pointer-events-none absolute inset-0 opacity-30" style={{ backgroundImage: 'linear-gradient(rgba(255,255,255,.045) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.045) 1px, transparent 1px)', backgroundSize: '56px 56px', maskImage: 'radial-gradient(ellipse at 30% 35%, black 0%, transparent 78%)', WebkitMaskImage: 'radial-gradient(ellipse at 30% 35%, black 0%, transparent 78%)' }} />
        <div className="ss-login-reveal relative flex items-center gap-3" style={{ animationDelay: '160ms' }}>
          <Image src="/logo_white/SureSign_WLOGO.webp" alt="SureSign" width={32} height={32} className="h-8 w-8 object-contain" />
          <span className="text-base font-semibold tracking-tight">SureSign</span>
        </div>

        <div className="relative max-w-md">
          <p className="ss-login-reveal mb-6 text-[10px] font-semibold uppercase tracking-[0.2em] text-[#9ee5b5]" style={{ animationDelay: '260ms' }}>Secure account recovery</p>
          <h1 className="ss-login-reveal text-[2.5rem] font-semibold leading-[1.04] tracking-[-0.045em] xl:text-5xl" style={{ animationDelay: '340ms' }}>Access restored.<br /><span className="text-white/40">Records protected.</span></h1>
          <p className="ss-login-reveal mt-5 max-w-sm text-sm leading-6 text-[#aebbb5]" style={{ animationDelay: '420ms' }}>A deliberate recovery path for the people responsible for contracts, deadlines and project records.</p>
          <div className="ss-login-reveal mt-9 space-y-3" style={{ animationDelay: '500ms' }}>
            {[
              [ShieldCheck, 'Secure reset links'],
              [FileKey2, 'Existing access is replaced'],
              [CheckCircle2, 'Return directly to your workspace'],
            ].map(([ItemIcon, label]) => {
              const FeatureIcon = ItemIcon as typeof ShieldCheck;
              return <div key={label as string} className="flex items-center gap-3 text-xs text-[#c4cec9]"><span className="flex h-8 w-8 items-center justify-center rounded-lg bg-white/[0.06] text-[#9ee5b5]"><FeatureIcon size={14} /></span>{label as string}</div>;
            })}
          </div>
        </div>

        <p className="relative text-[10px] text-white/25">© 2026 SureSign Contracts</p>
      </aside>

      <main className="relative flex flex-1 items-center justify-center overflow-hidden px-5 py-10 sm:px-10">
        <div className="pointer-events-none absolute -right-24 -top-24 h-80 w-80 rounded-full border border-[#18211d]/[0.05]" />
        <div className="w-full max-w-[460px]">
          <div className="ss-login-reveal mb-10 flex items-center gap-2.5 lg:hidden" style={{ animationDelay: '120ms' }}>
            <Image src="/logo_black/SureSign_BLOGO.webp" alt="SureSign" width={28} height={28} className="h-7 w-7 object-contain" />
            <span className="text-base font-semibold text-[#18211d]">SureSign</span>
          </div>

          <section className="ss-login-reveal overflow-hidden rounded-2xl bg-white shadow-[0_24px_70px_rgba(24,33,29,0.12)]" style={{ animationDelay: '220ms' }}>
            <header className="border-b border-[#e9ecea] px-6 pb-6 pt-7 sm:px-8 sm:pt-8">
              <div className="mb-6 flex h-11 w-11 items-center justify-center rounded-xl bg-[#0f0f0f] text-white"><Icon size={18} strokeWidth={1.8} /></div>
              <p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-[#686762]">{eyebrow}</p>
              <h2 className="mt-3 text-3xl font-semibold leading-[1.05] tracking-[-0.045em] text-[#18211d]">{title}</h2>
              <p className="mt-3 max-w-[38ch] text-sm leading-6 text-[#68736d]">{description}</p>
            </header>
            <div className="px-6 py-6 sm:px-8 sm:py-7">{children}</div>
          </section>
        </div>
      </main>
    </div>
  );
}
