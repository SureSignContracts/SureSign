'use client';

import { useRef, useState } from 'react';
import Image from 'next/image';
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
      onClose={() => onComplete(actionRef.current)}
    >
      {(close) => (
        <div className="grid min-h-0 gap-6 md:grid-cols-[0.82fr_1.18fr] md:gap-8">
          <div
            className="relative hidden min-h-[390px] overflow-hidden rounded-2xl border md:flex md:flex-col md:justify-between"
            style={{
              backgroundColor: '#11110f',
              borderColor: 'rgba(255,255,255,0.09)',
            }}
          >
            <div className="relative z-10 p-6">
              <Image
                src="/logo_white/SureSign_WLOGO.webp"
                alt="SureSign"
                width={32}
                height={32}
                className="h-8 w-8 object-contain"
              />
              <p className="mt-16 max-w-[14ch] text-[1.65rem] font-semibold leading-[1.08] tracking-[-0.045em] text-white">
                Run the contract, not the paperwork.
              </p>
              <p className="mt-3 max-w-[30ch] text-xs leading-relaxed text-white/55">
                A practical workspace for construction contract administration.
              </p>
            </div>

            <div className="relative h-44">
              <Image
                src="/dashboard/hero-construction.webp"
                alt="Construction professionals reviewing project work"
                fill
                sizes="360px"
                className="object-contain object-bottom opacity-90"
              />
            </div>
          </div>

          <div className="flex min-h-[390px] flex-col">
            <div key={screen} className="ss-welcome-screen flex-1">
              <p className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>
                {screen + 1} of {SCREENS.length}
              </p>
              <h3 className="mt-4 max-w-[16ch] text-[1.75rem] font-semibold leading-[1.08] tracking-[-0.04em]" style={{ color: 'var(--text-primary)' }}>
                {current.title}
              </h3>
              <p className="mt-3 max-w-[48ch] text-sm leading-relaxed" style={{ color: 'var(--text-secondary)' }}>
                {current.description}
              </p>

              <div className="mt-8 space-y-5">
                {current.points.map(({ icon: PointIcon, title, body }) => (
                  <div key={title} className="flex items-start gap-3">
                    <div
                      className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl border"
                      style={{ backgroundColor: 'var(--bg-elevated)', borderColor: 'var(--border)', color: 'var(--text-secondary)' }}
                    >
                      <PointIcon size={16} strokeWidth={1.75} />
                    </div>
                    <div>
                      <p className="text-xs font-semibold" style={{ color: 'var(--text-primary)' }}>{title}</p>
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
                    style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
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
                      style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
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
