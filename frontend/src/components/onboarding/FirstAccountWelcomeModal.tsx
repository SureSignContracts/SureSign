'use client';

import { useRef, useState } from 'react';
import {
  ArrowLeft,
  ArrowRight,
  BookOpenCheck,
  CalendarClock,
  FileStack,
  FolderKanban,
  Route,
} from 'lucide-react';
import Modal from '@/components/ui/Modal';

type WelcomeAction = 'explore' | 'tour';

const SCREENS = [
  {
    title: 'Everything starts with the contract',
    description: 'SureSign turns contract terms, project records and deadlines into one working source of truth.',
    icon: BookOpenCheck,
    points: [
      { icon: FolderKanban, title: 'Organise each project', body: 'Keep contracts, notices and supporting records together.' },
      { icon: CalendarClock, title: 'See what needs attention', body: 'Track dates and obligations before they become problems.' },
    ],
  },
  {
    title: 'Your work stays connected',
    description: 'Move from a project to its commercial records and documents without rebuilding the same context.',
    icon: Route,
    points: [
      { icon: FileStack, title: 'One document trail', body: 'Find the latest project record and the history behind it.' },
      { icon: CalendarClock, title: 'Practical reminders', body: 'Use the dashboard to focus on overdue and upcoming work.' },
    ],
  },
  {
    title: 'Ready when you are',
    description: 'Take a short tour of the workspace, or start exploring and return to Guided Tours from Help at any time.',
    icon: FolderKanban,
    points: [
      { icon: BookOpenCheck, title: 'A useful first stop', body: 'Open Projects, then add or review the main contract.' },
      { icon: Route, title: 'Help stays close', body: 'Page tours explain each workflow when you need them.' },
    ],
  },
] as const;

export default function FirstAccountWelcomeModal({
  firstName,
  onComplete,
}: {
  firstName: string;
  onComplete: (action: WelcomeAction) => void;
}) {
  const [screen, setScreen] = useState(0);
  const actionRef = useRef<WelcomeAction>('explore');
  const current = SCREENS[screen];

  function finish(close: () => void, action: WelcomeAction) {
    actionRef.current = action;
    close();
  }

  return (
    <Modal
      title={`Welcome to SureSign${firstName ? `, ${firstName}` : ''}`}
      icon={current.icon}
      size="xl"
      showCloseButton
      borderless
      onClose={() => onComplete(actionRef.current)}
    >
      {(close) => (
        <div className="grid min-h-0 gap-6 md:grid-cols-[0.88fr_1.12fr] md:gap-8">
          <div
            className="relative hidden min-h-[440px] overflow-hidden rounded-2xl bg-[#18211d] md:flex md:flex-col md:justify-between"
          >
            <div className="pointer-events-none absolute -right-20 -top-24 h-72 w-72 rounded-full bg-[#9ee5b5]/10 blur-3xl" />
            <div className="relative z-10 p-7">
              <div className="flex items-center justify-between">
                <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-[#9ee5b5] font-bold text-[#18211d]">S</div>
                <span className="font-mono text-[10px] uppercase tracking-[0.16em] text-white/30">Chapter 0{screen + 1}</span>
              </div>
              <p className="mt-12 max-w-[13ch] text-[1.8rem] font-semibold leading-[1.06] tracking-[-0.045em] text-white">
                Run the contract, not the paperwork.
              </p>
              <p className="mt-4 max-w-[31ch] text-xs leading-5 text-white/45">A connected operating record for construction delivery.</p>
            </div>

            <div key={screen} className="ss-welcome-screen relative mx-7 mb-7 rounded-2xl bg-white/[0.045] p-5">
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-[#9ee5b5] text-[#18211d]"><current.icon size={18} /></div>
                <div>
                  <p className="text-[10px] uppercase tracking-[0.14em] text-white/30">Working record</p>
                  <p className="mt-1 text-sm font-medium text-white/80">Contract intelligence</p>
                </div>
              </div>
              <div className="my-4 h-px bg-white/10" />
              <div className="grid grid-cols-[1fr_auto_1fr_auto_1fr] items-center gap-2 text-center">
                {['Contract', 'Actions', 'Evidence'].map((label, index) => (
                  <div key={label} className="contents">
                    <div>
                      <span className="mx-auto flex h-7 w-7 items-center justify-center rounded-lg bg-white/[0.07] text-[10px] font-semibold text-[#9ee5b5]">0{index + 1}</span>
                      <p className="mt-2 text-[9px] text-white/40">{label}</p>
                    </div>
                    {index < 2 && <ArrowRight size={11} className="text-white/20" />}
                  </div>
                ))}
              </div>
            </div>
          </div>

          <div className="flex min-h-[440px] flex-col py-1">
            <div key={screen} className="ss-welcome-screen flex-1">
              <div className="flex items-center gap-3">
                <p className="font-mono text-[10px] font-semibold uppercase tracking-[0.15em] text-[#3f8f60]">0{screen + 1} / 0{SCREENS.length}</p>
                <div className="h-1 flex-1 overflow-hidden rounded-full bg-[#e7ece8]"><div className="h-full rounded-full bg-[#68d391] transition-all duration-500" style={{ width: `${((screen + 1) / SCREENS.length) * 100}%` }} /></div>
              </div>
              <h3 className="mt-4 max-w-[16ch] text-[1.75rem] font-semibold leading-[1.08] tracking-[-0.04em]" style={{ color: 'var(--text-primary)' }}>
                {current.title}
              </h3>
              <p className="mt-3 max-w-[48ch] text-sm leading-relaxed" style={{ color: 'var(--text-secondary)' }}>
                {current.description}
              </p>

              <div className="mt-7 space-y-2">
                {current.points.map(({ icon: PointIcon, title, body }) => (
                  <div key={title} className="group flex items-start gap-3 rounded-2xl bg-[#f3f6f4] p-4 transition duration-200 hover:-translate-y-0.5 hover:bg-[#edf3ef]">
                    <div
                      className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-white text-[#247044] shadow-[0_4px_14px_rgba(24,33,29,0.05)] transition-transform group-hover:scale-105"
                    >
                      <PointIcon size={16} strokeWidth={1.75} />
                    </div>
                    <div>
                      <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{title}</p>
                      <p className="mt-1 max-w-[42ch] text-xs leading-relaxed" style={{ color: 'var(--text-muted)' }}>{body}</p>
                    </div>
                  </div>
                ))}
              </div>
            </div>

            <div className="mt-7 flex flex-col gap-4 border-t pt-5 sm:flex-row sm:items-center sm:justify-between" style={{ borderColor: 'var(--border)' }}>
              <div className="flex items-center gap-1.5" aria-label={`Screen ${screen + 1} of ${SCREENS.length}`}>
                {SCREENS.map((item, index) => (
                  <button
                    key={item.title}
                    type="button"
                    onClick={() => setScreen(index)}
                    aria-label={`Show ${item.title}`}
                    aria-current={index === screen ? 'step' : undefined}
                    className="h-1.5 rounded-full transition-[width,background-color] duration-300"
                    style={{
                      width: index === screen ? '1.5rem' : '0.4rem',
                      backgroundColor: index === screen ? 'var(--text-primary)' : 'var(--border-light)',
                    }}
                  />
                ))}
              </div>

              <div className="flex flex-wrap items-center justify-end gap-2">
                {screen > 0 && (
                  <button
                    type="button"
                    onClick={() => setScreen(value => value - 1)}
                    className="inline-flex h-10 items-center gap-1.5 whitespace-nowrap rounded-xl px-3 text-xs font-medium transition-colors hover:bg-[var(--bg-hover)] active:translate-y-px"
                    style={{ color: 'var(--text-secondary)' }}
                  >
                    <ArrowLeft size={14} /> Back
                  </button>
                )}

                {screen < SCREENS.length - 1 ? (
                  <button
                    type="button"
                    onClick={() => setScreen(value => value + 1)}
                    className="inline-flex h-10 items-center gap-2 whitespace-nowrap rounded-xl px-4 text-xs font-semibold transition-[transform,opacity] hover:-translate-y-0.5 hover:opacity-90 active:translate-y-px"
                    style={{ backgroundColor: '#18211d', color: '#ffffff' }}
                  >
                    Next <ArrowRight size={14} />
                  </button>
                ) : (
                  <>
                    <button
                      type="button"
                      onClick={() => finish(close, 'explore')}
                      className="h-10 whitespace-nowrap rounded-xl px-3 text-xs font-medium transition-colors hover:bg-[var(--bg-hover)] active:translate-y-px"
                      style={{ color: 'var(--text-secondary)' }}
                    >
                      Explore workspace
                    </button>
                    <button
                      type="button"
                      onClick={() => finish(close, 'tour')}
                      className="inline-flex h-10 items-center gap-2 whitespace-nowrap rounded-xl px-4 text-xs font-semibold transition-[transform,opacity] hover:-translate-y-0.5 hover:opacity-90 active:translate-y-px"
                      style={{ backgroundColor: '#9ee5b5', color: '#18211d' }}
                    >
                      Take quick tour <ArrowRight size={14} />
                    </button>
                  </>
                )}
              </div>
            </div>
          </div>
        </div>
      )}
    </Modal>
  );
}
