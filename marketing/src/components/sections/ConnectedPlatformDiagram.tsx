'use client';

import { useEffect, useRef, useState } from 'react';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';

const MODULES = [
  'Trade Packages',
  'Commercial',
  'Documents',
  'Drawings',
  'Programme',
  'Risks',
  'Notifications',
  'Calendar',
  'Final Accounts',
  'Site Reports',
  // The tspan wrap below splits on literal spaces only — a non-breaking
  // space between "&" and "Safety" keeps this to two lines ("Health" /
  // "& Safety") instead of three (a lone "&" on its own line).
  'Health & Safety',
  'Friday Packs',
];

// SIZE/RADIUS were sized for 8 evenly-spaced nodes; adding Drawings as a
// ninth tightened the gap between neighbouring circles at the old RADIUS.
// Both bumped up together (SIZE keeps a comfortable margin around
// RADIUS + NODE_R so the outer glow and nodes never clip the viewBox) so
// nine nodes get the same breathing room eight used to have.
//
// Marketing Refresh Batch 2 — Site Reports/Health & Safety/Friday Packs
// added as a third trio of nodes (12 total). Same reasoning applied again:
// RADIUS/SIZE bumped together to preserve per-node arc spacing, NODE_R
// trimmed slightly (60 → 56) since two-word labels already wrap onto a
// second line via the existing tspan logic below, so a touch less width
// per node doesn't cost legibility.
const SIZE = 980;
const CENTER = SIZE / 2;
const RADIUS = 390;
const NODE_R = 56;
const CENTER_R = 84;

const MODULE_DETAILS = [
  ['Scope and folders', 'for every trade'],
  ['Payments, notices', 'and variations'],
  ['Supporting evidence', 'in one place'],
  ['Revisions linked', 'to the project'],
  ['Milestones and', 'contract dates'],
  ['Track issues', 'and their impact'],
  ['Keep the team', 'up to date'],
  ['Key dates', 'kept together'],
  ['Bring the commercial', 'record together'],
  ['Capture progress', 'from the site'],
  ['Inspections and', 'safety records'],
  ['The week’s progress', 'in one pack'],
];

function angleFor(index: number) {
  return (index / MODULES.length) * Math.PI * 2 - Math.PI / 2;
}

// Math.cos/Math.sin can differ by 1 ULP between Node's server-side V8 and
// the browser's V8 build, which showed up as a React hydration mismatch on
// this SVG path's `d` string (server and client rendering different last
// digits). Rounding well above that noise floor makes the string identical
// in both environments — sub-hundredth-of-a-pixel precision is invisible
// anyway.
function round(n: number) {
  return Math.round(n * 100) / 100;
}

function nodePosition(index: number) {
  const angle = angleFor(index);
  return {
    x: round(CENTER + RADIUS * Math.cos(angle)),
    y: round(CENTER + RADIUS * Math.sin(angle)),
  };
}

// A gentle, consistent bow on every connection — reads as one elegant
// system of curves rather than a spoked wheel of straight lines.
function connectionPath(index: number) {
  const angle = angleFor(index);
  const { x, y } = nodePosition(index);
  const controlAngle = angle - 0.18;
  const controlRadius = RADIUS * 0.52;
  const cx = round(CENTER + controlRadius * Math.cos(controlAngle));
  const cy = round(CENTER + controlRadius * Math.sin(controlAngle));
  return `M ${CENTER} ${CENTER} Q ${cx} ${cy} ${x} ${y}`;
}

export function ConnectedPlatformDiagram() {
  const ref = useRef<SVGSVGElement>(null);
  const reduced = useReducedMotion();
  const [active, setActive] = useState<number | null>(null);
  const [hovered, setHovered] = useState<number | null>(null);
  const [focused, setFocused] = useState<number | null>(null);
  const selected = hovered ?? focused ?? active;
  const hub = useRef<SVGGElement>(null);
  const signal = useRef<SVGPathElement>(null);
  const halo = useRef<SVGCircleElement>(null);

  useEffect(() => {
    if (reduced) return;
    const { gsap } = getGsap();
    const ctx = gsap.context(() => {
      gsap.fromTo(hub.current, { opacity: 0, y: 8 }, {
        opacity: 1, y: 0, duration: 0.38, ease: 'power2.out',
      });
      if (selected === null) return;
      gsap.fromTo(halo.current, { attr: { r: CENTER_R + 8 }, opacity: 0.5 }, {
        attr: { r: CENTER_R + 25 }, opacity: 0, duration: 0.85, ease: 'power2.out',
      });
      if (signal.current) {
        const length = signal.current.getTotalLength();
        gsap.fromTo(signal.current, {
          strokeDasharray: length, strokeDashoffset: length,
        }, { strokeDashoffset: 0, duration: 0.65, ease: 'power2.inOut' });
      }
    }, ref);
    return () => ctx.revert();
  }, [selected, reduced]);

  useEffect(() => {
    if (reduced || !ref.current) return;
    const { gsap } = getGsap();
    const ctx = gsap.context(() => {
      const lines = gsap.utils.toArray<SVGPathElement>('[data-connection]');
      lines.forEach((line) => {
        const length = line.getTotalLength ? line.getTotalLength() : 320;
        gsap.set(line, { strokeDasharray: length, strokeDashoffset: length });
        gsap.to(line, {
          strokeDashoffset: 0,
          duration: 1.1,
          ease: 'power1.inOut',
          scrollTrigger: { trigger: ref.current, start: 'top 70%', end: 'top 5%', scrub: 0.8 },
        });
      });

      const nodes = gsap.utils.toArray<SVGGElement>('[data-node]');
      gsap.set(nodes, { scale: 0.85, transformOrigin: 'center' });
      gsap.to(nodes, {
        scale: 1,
        duration: 0.6,
        stagger: 0.12,
        ease: 'power1.out',
        scrollTrigger: { trigger: ref.current, start: 'top 65%', end: 'top 15%', scrub: 0.8 },
      });

      // A deliberate, one-time beat once the main reveal has settled —
      // Site Reports, Health & Safety and Friday Packs pulse in that
      // order, calling out the newest connected records without
      // redrawing the diagram's existing hub-and-spoke connectors.
      const newNodes = gsap.utils.toArray<SVGGElement>('[data-node-new]');
      if (newNodes.length) {
        gsap.timeline({
          scrollTrigger: { trigger: ref.current, start: 'top 12%', once: true },
        }).to(newNodes, {
          scale: 1.1,
          duration: 0.35,
          stagger: 0.18,
          ease: 'power1.out',
          yoyo: true,
          repeat: 1,
          transformOrigin: 'center',
        });
      }
    }, ref);

    return () => ctx.revert();
  }, [reduced]);

  return (
    <svg
      ref={ref}
      viewBox={`0 0 ${SIZE} ${SIZE}`}
      className="mx-auto h-auto w-full max-w-[800px]"
      role="group"
      aria-label="Diagram showing the Contract at the centre of the platform, connected to Trade Packages, Commercial, Documents, Drawings, Programme, Risks, Notifications, Calendar, Final Accounts, Site Reports, Health & Safety, and Friday Packs."
    >
      <defs>
        <radialGradient id="platform-glow" cx="50%" cy="50%" r="50%">
          <stop offset="0%" stopColor="var(--spotlight)" />
          <stop offset="100%" stopColor="transparent" />
        </radialGradient>
      </defs>
      <circle cx={CENTER} cy={CENTER} r={RADIUS + 90} fill="url(#platform-glow)" />

      {MODULES.map((_, i) => {
        const isActive = selected === i;
        const isDimmed = selected !== null && !isActive;
        return (
          <path
            key={`line-${i}`}
            data-connection
            d={connectionPath(i)}
            fill="none"
            strokeWidth={isActive ? 2 : 1.5}
            className="transition-[stroke,opacity] duration-300"
            style={{
              stroke: isActive ? 'var(--text-primary)' : 'var(--border-light)',
              opacity: isDimmed ? 0.22 : 1,
            }}
          />
        );
      })}

      {selected !== null && (
        <path ref={signal} d={connectionPath(selected)} fill="none" stroke="var(--text-primary)" strokeWidth={2.5} pointerEvents="none" />
      )}

      <g data-node aria-live="polite" aria-atomic="true" pointerEvents="none">
        <circle ref={halo} cx={CENTER} cy={CENTER} r={CENTER_R + 8} fill="none" stroke="var(--text-primary)" opacity={0} />
        <circle
          cx={CENTER}
          cy={CENTER}
          r={selected !== null ? CENTER_R + 12 : CENTER_R}
          className="fill-accent transition-[r] duration-300"
        />
        <g ref={hub}>
          <text x={CENTER} y={CENTER - 32} textAnchor="middle" className="fill-accent-fg text-[9px] uppercase tracking-wide opacity-70">
            {selected === null ? 'Single source of truth' : 'Connected to your contract'}
          </text>
          <text x={CENTER} y={CENTER - 4} textAnchor="middle" dominantBaseline="middle" className="fill-accent-fg text-[17px] font-medium">
            {selected === null ? 'Contract' : MODULES[selected]}
          </text>
          <text x={CENTER} y={CENTER + 23} textAnchor="middle" className="fill-accent-fg text-[11px] opacity-80">
            {(selected === null ? ['One confirmed record', 'for every workflow'] : MODULE_DETAILS[selected]).map((line, index) => (
              <tspan key={index} x={CENTER} dy={index === 0 ? 0 : 16}>{line}</tspan>
            ))}
          </text>
        </g>
      </g>

      {MODULES.map((label, i) => {
        const { x, y } = nodePosition(i);
        const isActive = selected === i;
        const isDimmed = selected !== null && !isActive;
        const isNewCapability = i >= MODULES.length - 3;
        return (
          <g
            key={label}
            data-node
            data-node-new={isNewCapability ? '' : undefined}
            tabIndex={0}
            role="button"
            aria-label={label}
            aria-pressed={isActive}
            onMouseEnter={() => setHovered(i)}
            onMouseLeave={() => setHovered(null)}
            onFocus={() => setFocused(i)}
            onBlur={() => setFocused(null)}
            onClick={() => setActive(i)}
            onKeyDown={(event) => {
              if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                setActive(i);
              } else if (event.key === 'Escape') {
                setActive(null);
                setHovered(null);
                setFocused(null);
                event.currentTarget.blur();
              }
            }}
            className="cursor-pointer outline-none transition-opacity duration-300"
            style={{ opacity: isDimmed ? 0.6 : 1 }}
          >
            <circle
              cx={x}
              cy={y}
              r={isActive ? NODE_R + 5 : NODE_R}
              className="fill-bg-surface transition-[r] duration-300"
              stroke={isActive ? 'var(--text-primary)' : 'var(--border)'}
              strokeWidth={isActive ? 2 : 1.5}
            />
            <text
              x={x}
              y={y}
              textAnchor="middle"
              dominantBaseline="middle"
              className="fill-text-primary text-[12px] font-medium"
            >
              {label.split(' ').map((word, wi) => (
                <tspan key={wi} x={x} dy={wi === 0 ? -((label.split(' ').length - 1) * 7) : 14}>
                  {word}
                </tspan>
              ))}
            </text>
          </g>
        );
      })}
    </svg>
  );
}
