'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import {
  Compass, CheckCircle2, RotateCcw, ArrowRight, X, Loader2,
  Rocket, FolderKanban, Package, DollarSign, ClipboardList, FolderOpen,
} from 'lucide-react';
import { useAuthStore } from '@/store/authStore';
import { useTour } from '@/lib/tours/useTour';
import { TOURS, GROUP_ORDER } from '@/lib/tours/registry';
import { resolveTourLaunch, ResolvedProject } from '@/lib/tours/launch';
import { setPendingTour } from '@/lib/tours/pending';
import type { TourDef } from '@/lib/tours/types';

const GROUP_ICONS: Record<string, React.ElementType> = {
  'Getting Started': Rocket,
  'Projects and Contracts': FolderKanban,
  'Trade Packages': Package,
  'Commercial': DollarSign,
  'Project Administration': ClipboardList,
  'Documents and Compliance': FolderOpen,
};

interface MissingDataState {
  tour: TourDef;
  kind: 'project' | 'trade-package';
  project?: ResolvedProject;
}

// Explains, rather than silently failing, when a tour's destination route
// needs an accessible record the user doesn't have yet — see
// lib/tours/launch.ts for how "missing" is determined. Every action here
// links to the real creation/browsing workflow (no fabricated demo data —
// see the Batch 2 report for why a safe demo mode wasn't practical here).
function MissingDataDialog({ state, onClose }: { state: MissingDataState; onClose: () => void }) {
  const dialogRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    dialogRef.current?.querySelector<HTMLElement>('a, button')?.focus();
    function handleKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onClose();
    }
    document.addEventListener('keydown', handleKey);
    return () => document.removeEventListener('keydown', handleKey);
  }, [onClose]);

  const isProjectMissing = state.kind === 'project';

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm"
      style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}
      onClick={onClose}
    >
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="missing-data-title"
        onClick={e => e.stopPropagation()}
        className="w-full max-w-sm rounded-2xl overflow-hidden"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: '0 20px 60px rgba(0,0,0,0.35)' }}
      >
        <div className="flex items-start justify-between gap-3 px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 id="missing-data-title" className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
            {state.tour.label} needs more data
          </h2>
          <button onClick={onClose} aria-label="Close" className="flex-shrink-0 p-1 rounded hover:opacity-70" style={{ color: 'var(--text-muted)' }}>
            <X size={15} />
          </button>
        </div>
        <div className="p-5 space-y-4">
          <p className="text-sm leading-relaxed" style={{ color: 'var(--text-secondary)' }}>
            {isProjectMissing
              ? 'This tour needs at least one project you can access. You don’t have one yet.'
              : `This tour needs a trade package. "${state.project?.name}" doesn’t have one yet.`}
          </p>
          <div className="flex flex-col gap-2">
            <Link
              href={isProjectMissing ? '/app/projects' : `/app/projects/${state.project?.id}/contracts`}
              onClick={onClose}
              className="flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-lg text-sm font-medium transition-opacity hover:opacity-90 active:scale-[0.98]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
            >
              {isProjectMissing ? 'Go to Projects' : 'Open Contracts'}
              <ArrowRight size={14} />
            </Link>
            <button
              onClick={onClose}
              className="px-4 py-2.5 rounded-lg text-sm font-medium transition-colors hover:bg-[var(--bg-hover)]"
              style={{ color: 'var(--text-secondary)' }}
            >
              Cancel
            </button>
          </div>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            Come back to Guided Tours once you have {isProjectMissing ? 'a project' : 'a trade package'} to pick this tour back up.
          </p>
        </div>
      </div>
    </div>
  );
}

function TourRow({
  tour,
  completed,
  launching,
  onLaunch,
}: {
  tour: TourDef;
  completed: boolean;
  launching: boolean;
  onLaunch: () => void;
}) {
  return (
    <div className="flex items-center justify-between gap-3 px-5 py-3.5" style={{ borderBottom: '1px solid var(--border)' }}>
      <div className="min-w-0">
        <div className="flex items-center gap-2">
          <p className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>{tour.label}</p>
          {completed && (
            <span className="inline-flex items-center gap-1 text-xs flex-shrink-0" style={{ color: '#4ade80' }}>
              <CheckCircle2 size={12} /> Completed
            </span>
          )}
        </div>
        <p className="text-xs mt-0.5 truncate" style={{ color: 'var(--text-muted)' }}>{tour.description}</p>
      </div>
      <button
        onClick={onLaunch}
        disabled={launching}
        className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium transition-all active:scale-[0.98] hover:opacity-90 disabled:opacity-60 flex-shrink-0"
        style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
      >
        {launching ? <Loader2 size={12} className="animate-spin" /> : <RotateCcw size={12} />}
        {completed ? 'Restart' : 'Start'}
      </button>
    </div>
  );
}

export default function GuidedToursPage() {
  const router = useRouter();
  const roles = useAuthStore(s => s.user?.roles ?? []);
  const { isTourCompleted } = useTour();
  const [launchingKey, setLaunchingKey] = useState<string | null>(null);
  const [missingData, setMissingData] = useState<MissingDataState | null>(null);

  // Tours with a `roles` restriction that the current user doesn't hold are
  // left out entirely, rather than shown with a Start button that would
  // silently do nothing — useTour().startTour applies the same check before
  // running a tour.
  const visibleTours = useMemo(
    () => TOURS.filter(t => !t.roles || t.roles.some(r => roles.includes(r))),
    [roles],
  );

  const completedCount = visibleTours.filter(t => isTourCompleted(t.key)).length;
  const totalCount = visibleTours.length;
  const progressPct = totalCount === 0 ? 0 : Math.round((completedCount / totalCount) * 100);
  const nextTour = visibleTours.find(t => !isTourCompleted(t.key)) ?? null;

  async function handleLaunch(tour: TourDef) {
    setLaunchingKey(tour.key);
    try {
      const result = await resolveTourLaunch(tour.key);
      if (result.status === 'ready') {
        setPendingTour(tour.key);
        router.push(result.route);
      } else if (result.status === 'missing-project') {
        setMissingData({ tour, kind: 'project' });
      } else if (result.status === 'missing-trade-package') {
        setMissingData({ tour, kind: 'trade-package', project: result.project });
      }
    } finally {
      setLaunchingKey(null);
    }
  }

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6">
      {/* Header */}
      <div className="flex items-start gap-3">
        <div className="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" style={{ backgroundColor: 'var(--gold-15)' }}>
          <Compass size={20} style={{ color: 'var(--gold)' }} />
        </div>
        <div>
          <h1 className="text-[1.75rem] font-bold" style={{ color: 'var(--text-primary)' }}>Guided Tours</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
            Walkthroughs of every page in SureSign, grouped by where you&apos;ll use them.
          </p>
        </div>
      </div>

      {/* Progress */}
      <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <div className="flex items-center justify-between mb-2">
          <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
            {completedCount} of {totalCount} tours completed
          </p>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{progressPct}%</p>
        </div>
        <div className="h-2 rounded-full overflow-hidden" style={{ backgroundColor: 'var(--bg-elevated)' }}>
          <div
            className="h-full rounded-full transition-all duration-300"
            style={{ width: `${progressPct}%`, backgroundColor: 'var(--gold)' }}
          />
        </div>
      </div>

      {/* Recommended next */}
      {nextTour && (
        <div
          className="rounded-2xl p-5 flex items-center justify-between gap-3"
          style={{ backgroundColor: 'var(--gold-15)', border: '1px solid var(--gold)' }}
        >
          <div className="min-w-0">
            <p className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--gold)' }}>Recommended next</p>
            <p className="text-sm font-medium mt-1" style={{ color: 'var(--text-primary)' }}>{nextTour.label}</p>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{nextTour.description}</p>
          </div>
          <button
            onClick={() => handleLaunch(nextTour)}
            disabled={launchingKey === nextTour.key}
            className="flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-medium transition-all active:scale-[0.98] hover:opacity-90 disabled:opacity-60 flex-shrink-0"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            {launchingKey === nextTour.key ? <Loader2 size={12} className="animate-spin" /> : <ArrowRight size={12} />}
            Start
          </button>
        </div>
      )}

      {/* Tour groups */}
      {GROUP_ORDER.map(group => {
        const tours = visibleTours.filter(t => t.group === group);
        if (tours.length === 0) return null;
        const GroupIcon = GROUP_ICONS[group] ?? Compass;
        return (
          <div key={group} className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <div className="flex items-center gap-2.5 px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
              <GroupIcon size={15} style={{ color: 'var(--text-secondary)' }} />
              <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{group}</h2>
            </div>
            <div>
              {tours.map(tour => (
                <TourRow
                  key={tour.key}
                  tour={tour}
                  completed={isTourCompleted(tour.key)}
                  launching={launchingKey === tour.key}
                  onLaunch={() => handleLaunch(tour)}
                />
              ))}
            </div>
          </div>
        );
      })}

      {missingData && <MissingDataDialog state={missingData} onClose={() => setMissingData(null)} />}
    </div>
  );
}
