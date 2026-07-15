'use client';

import { useEffect, useRef, useState } from 'react';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';

const MODULES = [
  'Trade Packages',
  'Commercial',
  'Documents',
  'Programme',
  'Risks',
  'Notifications',
  'Calendar',
  'Final Accounts',
];

const SIZE = 800;
const CENTER = SIZE / 2;
const RADIUS = 310;
const NODE_R = 60;
const CENTER_R = 84;

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
      gsap.set(nodes, { opacity: 0, scale: 0.85, transformOrigin: 'center' });
      gsap.to(nodes, {
        opacity: 1,
        scale: 1,
        duration: 0.6,
        stagger: 0.12,
        ease: 'power1.out',
        scrollTrigger: { trigger: ref.current, start: 'top 65%', end: 'top 15%', scrub: 0.8 },
      });
    }, ref);

    return () => ctx.revert();
  }, [reduced]);

  return (
    <svg
      ref={ref}
      viewBox={`0 0 ${SIZE} ${SIZE}`}
      className="mx-auto h-auto w-full max-w-[800px]"
      role="img"
      aria-label="Diagram showing the Contract at the centre of the platform, connected to Trade Packages, Commercial, Documents, Programme, Risks, Notifications, Calendar, and Final Accounts."
    >
      <defs>
        <radialGradient id="platform-glow" cx="50%" cy="50%" r="50%">
          <stop offset="0%" stopColor="var(--spotlight)" />
          <stop offset="100%" stopColor="transparent" />
        </radialGradient>
      </defs>
      <circle cx={CENTER} cy={CENTER} r={RADIUS + 90} fill="url(#platform-glow)" />

      {MODULES.map((_, i) => {
        const isActive = active === i;
        const isDimmed = active !== null && !isActive;
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

      <g data-node>
        <circle
          cx={CENTER}
          cy={CENTER}
          r={active !== null ? CENTER_R + 6 : CENTER_R}
          className="fill-accent transition-[r] duration-300"
        />
        <text x={CENTER} y={CENTER - 8} textAnchor="middle" dominantBaseline="middle" className="fill-accent-fg text-[18px] font-medium">
          Contract
        </text>
        <text x={CENTER} y={CENTER + 14} textAnchor="middle" dominantBaseline="middle" className="fill-accent-fg text-[10px] uppercase tracking-wide opacity-70">
          Single source of truth
        </text>
      </g>

      {MODULES.map((label, i) => {
        const { x, y } = nodePosition(i);
        const isActive = active === i;
        const isDimmed = active !== null && !isActive;
        return (
          <g
            key={label}
            data-node
            tabIndex={0}
            role="button"
            aria-label={label}
            onMouseEnter={() => setActive(i)}
            onMouseLeave={() => setActive(null)}
            onFocus={() => setActive(i)}
            onBlur={() => setActive(null)}
            className="cursor-pointer outline-none transition-opacity duration-300"
            style={{ opacity: isDimmed ? 0.4 : 1 }}
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
