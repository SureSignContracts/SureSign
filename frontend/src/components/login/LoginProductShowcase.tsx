'use client';

import { useEffect, useState, useSyncExternalStore } from 'react';
import {
  Activity,
  AlertTriangle,
  CalendarDays,
  Check,
  FileCheck2,
  FileText,
  Link2,
  MapPin,
  PoundSterling,
} from 'lucide-react';

const SLIDE_INTERVAL_MS = 3000;

const SLIDES = [
  {
    id: 'contract-intelligence',
    label: 'Contract intelligence',
    title: 'Understand the contract',
    description: 'Turn contract documents into structured obligations, dates, risks and commercial information.',
    Scene: ContractIntelligenceScene,
  },
  {
    id: 'commercial-control',
    label: 'Commercial control',
    title: 'Stay commercially in control',
    description: 'Manage applications, notices, variations and commercial deadlines from one connected workspace.',
    Scene: CommercialControlScene,
  },
  {
    id: 'drawing-coordination',
    label: 'Drawing coordination',
    title: 'Coordinate from the drawing',
    description: 'Link Snags, RFIs, QA records and Variations to the exact drawing revision, page and location.',
    Scene: DrawingCoordinationScene,
  },
  {
    id: 'project-record',
    label: 'Project record',
    title: 'Maintain one defensible project record',
    description: 'Keep documents, notices, commercial records and project activity connected and traceable.',
    Scene: ProjectRecordScene,
  },
] as const;

function subscribeToReducedMotion(onChange: () => void) {
  const query = window.matchMedia('(prefers-reduced-motion: reduce)');
  query.addEventListener('change', onChange);
  return () => query.removeEventListener('change', onChange);
}

function getReducedMotionSnapshot() {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function getServerReducedMotionSnapshot() {
  return false;
}

export default function LoginProductShowcase() {
  const [activeSlide, setActiveSlide] = useState(0);
  const [paused, setPaused] = useState(false);
  const reducedMotion = useSyncExternalStore(
    subscribeToReducedMotion,
    getReducedMotionSnapshot,
    getServerReducedMotionSnapshot,
  );

  useEffect(() => {
    if (paused || reducedMotion) return;

    const interval = window.setInterval(() => {
      if (!document.hidden) {
        setActiveSlide((current) => (current + 1) % SLIDES.length);
      }
    }, SLIDE_INTERVAL_MS);

    return () => window.clearInterval(interval);
  }, [activeSlide, paused, reducedMotion]);

  const active = SLIDES[activeSlide];

  return (
    <section
      aria-roledescription="carousel"
      aria-label="SureSign product capabilities"
      className="w-full"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      onFocusCapture={() => setPaused(true)}
      onBlurCapture={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setPaused(false);
      }}
    >
      <div
        className="relative h-[9.6rem] overflow-hidden rounded-[1.1rem] border p-1.5 xl:h-[10.2rem]"
        style={{
          backgroundColor: 'rgba(255,255,255,0.035)',
          borderColor: 'rgba(255,255,255,0.1)',
          boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.055), 0 18px 42px rgba(0,0,0,0.18)',
        }}
      >
        <div
          className="pointer-events-none absolute left-8 right-8 top-0 h-px"
          style={{ background: 'linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent)' }}
        />
        {SLIDES.map((slide, index) => (
          <article
            key={slide.id}
            id={`login-showcase-${slide.id}`}
            aria-hidden={index !== activeSlide}
            data-active={index === activeSlide}
            className="ss-showcase-slide absolute inset-1.5 overflow-hidden rounded-[calc(1.1rem-7px)]"
            style={{
              backgroundColor: '#101010',
              border: '1px solid rgba(255,255,255,0.07)',
            }}
          >
            <slide.Scene />
          </article>
        ))}
      </div>

      <div className="mt-4 grid min-h-[4.6rem] grid-cols-[1fr_auto] gap-5">
        <div aria-live="off" aria-atomic="true">
          <p className="text-sm font-semibold" style={{ color: 'rgba(255,255,255,0.82)' }}>
            {active.title}
          </p>
          <p className="mt-1.5 max-w-[31rem] text-xs leading-relaxed" style={{ color: 'rgba(255,255,255,0.42)' }}>
            {active.description}
          </p>
        </div>

        <div className="flex items-start gap-1.5 pt-1" role="group" aria-label="Choose a product capability">
          {SLIDES.map((slide, index) => (
            <button
              key={slide.id}
              type="button"
              aria-label={`Show ${slide.label}`}
              aria-controls={`login-showcase-${slide.id}`}
              aria-current={index === activeSlide ? 'true' : undefined}
              onClick={() => setActiveSlide(index)}
              className="group flex h-6 items-center justify-center rounded-md px-1 focus-visible:outline-offset-1"
            >
              <span
                className="h-1 rounded-full transition-[width,background-color] duration-300"
                style={{
                  width: index === activeSlide ? '1.25rem' : '0.35rem',
                  backgroundColor: index === activeSlide ? 'rgba(255,255,255,0.82)' : 'rgba(255,255,255,0.2)',
                }}
              />
            </button>
          ))}
        </div>
      </div>
    </section>
  );
}

function WindowBar({ section, detail }: { section: string; detail: string }) {
  return (
    <div
      className="flex h-7 items-center justify-between px-3"
      style={{ borderBottom: '1px solid rgba(255,255,255,0.07)', backgroundColor: 'rgba(255,255,255,0.018)' }}
    >
      <div className="flex items-center gap-2">
        <span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: '#68c58a' }} />
        <span className="text-[9px] font-semibold uppercase tracking-[0.16em]" style={{ color: 'rgba(255,255,255,0.42)' }}>
          {section}
        </span>
      </div>
      <span className="font-mono text-[8px]" style={{ color: 'rgba(255,255,255,0.25)' }}>{detail}</span>
    </div>
  );
}

function ContractIntelligenceScene() {
  return (
    <div className="h-full">
      <WindowBar section="Contract intelligence" detail="RIVERSIDE OFFICES" />
      <div className="grid h-[calc(100%_-_1.75rem)] grid-cols-[0.86fr_1.14fr] gap-2 p-2.5">
        <div className="ss-showcase-fragment relative overflow-hidden rounded-lg border border-white/[0.07] bg-white/[0.035] p-2.5">
          <div className="flex items-center gap-2">
            <span className="flex h-7 w-7 items-center justify-center rounded-md bg-white/[0.06] text-white/50">
              <FileText size={13} strokeWidth={1.7} />
            </span>
            <div className="min-w-0">
              <p className="truncate text-[9px] font-semibold text-white/70">JCT Design &amp; Build</p>
              <p className="mt-0.5 font-mono text-[7px] text-white/25">CONTRACT-001.pdf</p>
            </div>
          </div>
          <div className="mt-2.5 space-y-1.5">
            {[82, 66, 74, 48].map((width, index) => (
              <div key={width} className="h-1 rounded-full bg-white/[0.07]" style={{ width: `${width}%`, animationDelay: `${index * 55}ms` }} />
            ))}
          </div>
          <div className="absolute bottom-2.5 left-2.5 right-2.5 flex items-center gap-1.5 text-[8px] font-medium text-emerald-300/80">
            <Check size={9} strokeWidth={2} /> Contract analysed
          </div>
        </div>

        <div className="grid grid-cols-2 gap-2">
          {[
            { icon: CalendarDays, label: 'Payment terms', value: 'Monthly · 21 days', tone: '#d8d4c8' },
            { icon: FileCheck2, label: 'Key obligations', value: '12 confirmed', tone: '#80c99b' },
            { icon: AlertTriangle, label: 'Risk flags', value: '3 to review', tone: '#d2ad66' },
            { icon: CalendarDays, label: 'Next key date', value: '18 Aug 2026', tone: '#d8d4c8' },
          ].map(({ icon: Icon, label, value, tone }, index) => (
            <div
              key={label}
              className="ss-showcase-fragment rounded-lg border border-white/[0.07] bg-white/[0.035] p-2"
              style={{ animationDelay: `${90 + index * 55}ms` }}
            >
              <Icon size={10} strokeWidth={1.7} style={{ color: tone }} />
              <p className="mt-1.5 text-[7px] uppercase tracking-[0.12em] text-white/25">{label}</p>
              <p className="mt-0.5 truncate text-[8px] font-semibold text-white/65">{value}</p>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

function CommercialControlScene() {
  const metrics = [
    ['Application value', '£184,250'],
    ['Certified', '£171,800'],
    ['Retention', '£8,590'],
  ];

  return (
    <div className="h-full">
      <WindowBar section="Payment application #06" detail="GROUND WORKS" />
      <div className="grid h-[calc(100%_-_1.75rem)] grid-cols-[1.2fr_0.8fr] gap-2 p-2.5">
        <div className="rounded-lg border border-white/[0.07] bg-white/[0.025] p-2.5">
          <div className="grid grid-cols-3 gap-1.5">
            {metrics.map(([label, value], index) => (
              <div key={label} className="ss-showcase-fragment" style={{ animationDelay: `${index * 60}ms` }}>
                <p className="text-[7px] text-white/25">{label}</p>
                <p className="mt-1 font-mono text-[9px] font-semibold text-white/70">{value}</p>
              </div>
            ))}
          </div>
          <div className="my-2.5 h-px bg-white/[0.07]" />
          <div className="grid grid-cols-2 gap-3">
            <div>
              <p className="text-[7px] text-white/25">Due date</p>
              <p className="mt-0.5 text-[8px] font-medium text-white/60">18 Aug 2026</p>
            </div>
            <div>
              <p className="text-[7px] text-white/25">Final payment</p>
              <p className="mt-0.5 text-[8px] font-medium text-white/60">01 Sep 2026</p>
            </div>
          </div>
        </div>

        <div className="ss-showcase-fragment rounded-lg border border-white/[0.07] bg-white/[0.035] p-2.5" style={{ animationDelay: '170ms' }}>
          <div className="flex items-center justify-between">
            <span className="flex h-6 w-6 items-center justify-center rounded-md bg-amber-300/10 text-amber-200/70">
              <PoundSterling size={11} strokeWidth={1.8} />
            </span>
            <span className="rounded px-1.5 py-0.5 text-[7px] font-semibold text-amber-200/75" style={{ backgroundColor: 'rgba(210,173,102,0.12)' }}>
              Pending
            </span>
          </div>
          <p className="mt-2.5 font-mono text-[7px] text-white/25">VAR-018</p>
          <p className="mt-1 text-[9px] font-semibold leading-tight text-white/65">Revised Ground Works</p>
          <div className="mt-2 h-1 overflow-hidden rounded-full bg-white/[0.07]">
            <div className="ss-showcase-progress h-full w-[68%] rounded-full bg-amber-200/60" />
          </div>
        </div>
      </div>
    </div>
  );
}

function DrawingCoordinationScene() {
  return (
    <div className="h-full">
      <WindowBar section="Drawing S-204" detail="REV C01 · PAGE 4 / 9" />
      <div className="relative h-[calc(100%_-_1.75rem)] overflow-hidden bg-[#e6e5df]">
        <div
          className="absolute inset-0 opacity-65"
          style={{
            backgroundImage: 'linear-gradient(rgba(30,34,32,0.1) 1px, transparent 1px), linear-gradient(90deg, rgba(30,34,32,0.1) 1px, transparent 1px)',
            backgroundSize: '18px 18px',
          }}
        />
        <div className="absolute left-[13%] top-[20%] h-[58%] w-[60%] border border-[#565d58]/35">
          <div className="absolute left-[38%] top-0 h-full w-px bg-[#565d58]/25" />
          <div className="absolute left-0 top-[54%] h-px w-full bg-[#565d58]/25" />
          <div className="absolute bottom-[8%] right-[8%] h-[22%] w-[25%] border border-[#565d58]/25" />
        </div>

        <DrawingHotspot className="left-[31%] top-[31%]" delay="40ms" label="RFI-042" detail="Steel connection detail" />
        <DrawingHotspot className="left-[57%] top-[60%]" delay="120ms" label="SNAG-018" detail="Column finish" />
        <span className="ss-showcase-fragment absolute bottom-2.5 right-2.5 rounded border border-[#565d58]/20 bg-[#f4f3ee] px-2 py-1 text-[7px] font-semibold text-[#3d443f]/65" style={{ animationDelay: '210ms' }}>
          STRUCTURAL GA · C01
        </span>
      </div>
    </div>
  );
}

function DrawingHotspot({ className, delay, label, detail }: { className: string; delay: string; label: string; detail: string }) {
  return (
    <div className={`ss-showcase-fragment absolute ${className}`} style={{ animationDelay: delay }}>
      <span className="flex h-4 w-4 items-center justify-center rounded-full border-2 border-[#f4f3ee] bg-[#1f2521] shadow-md">
        <MapPin size={7} strokeWidth={2.2} color="#e4e1d7" />
      </span>
      <div className="absolute left-3 top-3 w-[6.6rem] rounded-md border border-black/10 bg-[#f8f7f2] px-2 py-1.5 shadow-[0_5px_14px_rgba(22,27,24,0.16)]">
        <p className="font-mono text-[7px] font-semibold text-[#252b27]">{label}</p>
        <p className="mt-0.5 truncate text-[7px] text-[#626761]">{detail}</p>
      </div>
    </div>
  );
}

function ProjectRecordScene() {
  const activity = [
    ['09:42', 'RFI-042 issued'],
    ['10:18', 'Drawing revision C01 added'],
    ['11:06', 'Variation VAR-018 updated'],
    ['12:34', 'Payment notice generated'],
  ];

  return (
    <div className="h-full">
      <WindowBar section="Project record" detail="RIVERSIDE OFFICES" />
      <div className="grid h-[calc(100%_-_1.75rem)] grid-cols-[0.72fr_1.28fr] gap-2 p-2.5">
        <div className="grid grid-cols-2 gap-1.5">
          {['Documents', 'Programme', 'RFIs', 'Variations', 'Notices', 'Site records'].map((item, index) => (
            <div
              key={item}
              className="ss-showcase-fragment flex items-center gap-1.5 rounded-md border border-white/[0.07] bg-white/[0.03] px-1.5 text-[7px] font-medium text-white/45"
              style={{ animationDelay: `${index * 35}ms` }}
            >
              <Link2 size={8} strokeWidth={1.8} className="shrink-0 text-white/25" />
              <span className="truncate">{item}</span>
            </div>
          ))}
        </div>

        <div className="rounded-lg border border-white/[0.07] bg-white/[0.035] px-2.5 py-2">
          <div className="mb-1.5 flex items-center justify-between">
            <span className="text-[8px] font-semibold text-white/55">Recorded activity</span>
            <Activity size={9} strokeWidth={1.7} className="text-white/25" />
          </div>
          {activity.map(([time, text], index) => (
            <div
              key={time}
              className="ss-showcase-fragment flex items-center gap-2 border-t border-white/[0.055] py-1"
              style={{ animationDelay: `${70 + index * 45}ms` }}
            >
              <span className="w-7 font-mono text-[6px] text-white/20">{time}</span>
              <span className="truncate text-[7px] text-white/48">{text}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
