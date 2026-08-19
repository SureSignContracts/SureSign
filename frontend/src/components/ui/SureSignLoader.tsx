'use client';

import { useEffect, useId, useRef } from 'react';
import { useQuery } from '@tanstack/react-query';
import { gsap } from 'gsap';
import api from '@/lib/api';

// Every value `loader_accent_style` (suresign_settings) can hold. Adding a
// new one is a two-line change (here + ACCENT_PALETTES below) plus a Select
// option in the admin Branding tab and PreviewPanel — nothing else in the
// animation/reveal logic needs to know a new style exists.
export const ACCENT_STYLES = ['monochrome', 'mint', 'christmas', 'halloween', 'new_year', 'valentines', 'easter'] as const;
export type AccentStyle = typeof ACCENT_STYLES[number];

// Single source of truth for display labels — reused by the admin Branding
// tab's Select and PreviewPanel's test buttons, so the two can never drift
// apart or disagree on what a style is called.
export const ACCENT_STYLE_LABELS: Record<AccentStyle, string> = {
  monochrome: 'Black & white',
  mint: 'Mint',
  christmas: 'Christmas',
  halloween: 'Halloween',
  new_year: 'New Year',
  valentines: "Valentine's",
  easter: 'Easter',
};

interface SureSignLoaderProps {
  /**
   * True once the app has actually become ready and this loader is only
   * still mounted for its brief GSAP exit transition (see
   * useAuthSplash's `loaderExiting`) — never a signal that delays
   * readiness itself.
   */
  exiting?: boolean;
  /**
   * Test-only — repeats the assembly sequence instead of settling into the
   * normal idle glow after playing once. Never set by real app code (the
   * three authenticated layouts always render this with the default,
   * play-once behaviour); exists solely for `PreviewPanel`'s manual test
   * button, so it can be watched repeatedly without re-clicking.
   */
  loop?: boolean;
  /**
   * `undefined` (real app usage, always) — follow the Super Admin-
   * configured `loader_accent_style` platform setting, fetched from the
   * public `/guest-settings` endpoint (the only one available this early —
   * the loader is shown while auth itself is still resolving, before any
   * authenticated call could succeed), defaulting to monochrome while that
   * fetch is pending or fails. An explicit `AccentStyle` — used only by
   * `PreviewPanel`'s manual test buttons to force a specific style
   * regardless of the live setting.
   */
  accent?: AccentStyle;
  /**
   * True (default) — the auth-resolving usage in the three authenticated
   * layouts, which replaces the ENTIRE page (no sidebar/chrome mounted
   * yet), so it fills the real viewport (`min-h-screen`). Pass `false`
   * when using this as a route-segment `loading.tsx` (see
   * `app/admin/loading.tsx` etc.) — there, the surrounding layout's
   * sidebar/chrome is already mounted and this only replaces the content
   * area next to it, so it should fill that area (`h-full`) instead of
   * forcing the whole page to at least one viewport tall.
   */
  fullScreen?: boolean;
}

const prefersReducedMotion = () =>
  typeof window !== 'undefined' &&
  window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

// A faithful vector trace of the real SureSign mark (the "S" + checkmark
// badge in /public/logo_black/SureSign_BLOGO.webp) — no clean vector of it
// exists anywhere in the repo, so this was produced mechanically with
// potrace directly from that raster asset (not hand-redrawn/guessed), then
// split along its own natural subpath boundaries into the four pieces the
// artwork is actually built from (verified by rendering each in isolation
// before trusting it): the rounded-square border ring, the S's top curl,
// the S's bottom curl, and the checkmark. Splitting these out is what lets
// the reveal assemble the mark piece by piece instead of one flat sweep.
// viewBox matches the source raster's own canvas (1536x1024).
const BORDER_PATH =
  'M 418.500 37.072 C 361.030 43.861, 316.525 85.318, 302.765 144.884 L 300.543 154.500 300.228 507.500 C 300 761.561, 300.223 863.303, 301.022 870.500 C 308.159 934.753, 354.287 982.031, 417.500 989.881 C 422.622 990.517, 550.332 990.966, 772.500 991.130 C 1097.049 991.369, 1120.082 991.275, 1128.500 989.667 C 1158.589 983.921, 1180.011 973.015, 1199.517 953.515 C 1219.559 933.478, 1231.591 910.443, 1237.340 881.104 C 1239.416 870.513, 1239.419 870.074, 1239.755 524.500 C 1240.102 167.637, 1240.058 163.096, 1236.074 144.500 C 1224.609 90.997, 1185.646 51.571, 1131.508 38.692 L 1122.500 36.549 774 36.397 C 582.325 36.314, 422.350 36.618, 418.500 37.072 M 422.925 92.975 C 389.153 97.361, 364.655 120.518, 354.662 157.500 L 352.500 165.500 352.847 514 C 353.139 806.697, 353.417 863.694, 354.588 869.956 C 360.965 904.057, 388.106 929.454, 423 933.970 C 429.077 934.756, 529.293 934.989, 774.500 934.785 L 1117.500 934.500 1124.961 932.188 C 1154.501 923.034, 1176.675 899.041, 1181.909 870.566 C 1183.759 860.505, 1183.418 172.888, 1181.558 162.321 C 1175.678 128.911, 1153.996 103.973, 1122.464 94.355 C 1116.751 92.612, 1102.023 92.530, 772.500 92.393 C 583.300 92.315, 425.991 92.577, 422.925 92.975';
const S_TOP_PATH =
  'M 718.920 213.099 C 707.601 213.461, 697.476 214.021, 696.420 214.342 C 695.364 214.664, 690.450 215.415, 685.500 216.013 C 632.522 222.403, 584.011 244.933, 549.957 278.964 C 498.407 330.480, 491.652 407.949, 533.195 471.215 C 539.858 481.362, 553.592 499, 554.831 499 C 555.267 499, 570.580 483.717, 588.859 465.039 L 622.095 431.078 617.062 424.674 C 603.270 407.124, 598.633 384.843, 604.534 364.480 C 616.776 322.234, 667.007 297, 738.862 297 C 782.858 297, 823.645 307.472, 856.278 327.147 L 860.056 329.425 880.595 308.963 C 891.892 297.708, 905.586 283.736, 911.027 277.913 L 920.921 267.325 910.710 259.855 C 862.800 224.802, 803.674 210.388, 718.920 213.099';
const S_BOTTOM_PATH =
  'M 931.014 468.483 C 929.896 469.830, 917.085 488.358, 889.312 528.795 C 883.708 536.954, 878.983 544.122, 878.812 544.725 C 878.640 545.328, 881.847 548.183, 885.938 551.069 C 915.697 572.064, 929.341 597.537, 927.679 629 C 925.242 675.137, 886.779 712.271, 828.681 724.578 C 796.247 731.448, 749.081 731.776, 714.500 725.372 C 651.491 713.704, 600.119 680.683, 568.460 631.500 C 565.274 626.550, 562.552 622.348, 562.413 622.163 C 562.036 621.662, 484.546 667.951, 484.224 668.870 C 483.408 671.196, 503.266 702.452, 514.485 716.500 C 562.385 776.479, 633.159 811.335, 729 822.151 C 746.556 824.132, 798.856 823.850, 817 821.676 C 926.975 808.497, 1000.393 754.791, 1022.017 671.702 C 1042.764 591.983, 1009.035 507.728, 941.918 471.617 C 932.160 466.368, 932.652 466.509, 931.014 468.483';
const CHECK_PATH =
  'M 1086.924 179.312 C 995.865 243.124, 962.854 271.223, 907.546 332 C 871.138 372.008, 799.847 459.135, 766.923 503.861 C 761.918 510.660, 757.485 515.835, 757.073 515.361 C 756.660 514.888, 751.889 509.123, 746.469 502.551 C 713.193 462.198, 690.844 448.017, 664.311 450.421 C 643.698 452.289, 627.549 462.201, 606.505 485.902 C 589.909 504.593, 587.418 507.982, 590.250 508.014 C 592.868 508.044, 612.062 517.991, 620.592 523.737 C 657.055 548.304, 689.502 581.984, 738.380 646 C 748.668 659.475, 758.268 672.033, 759.711 673.908 L 762.337 677.315 764.692 674.408 C 766.741 671.878, 793.041 630.008, 809.232 603.500 C 883.094 482.576, 899.805 457.737, 968.347 367 C 1024.088 293.209, 1051.473 260.785, 1096.551 215.206 L 1123.602 187.854 1116.178 176.427 C 1112.094 170.142, 1108.436 165, 1108.050 165 C 1107.664 165, 1098.157 171.441, 1086.924 179.312';
const FULL_PATH = `${BORDER_PATH} ${S_TOP_PATH} ${S_BOTTOM_PATH} ${CHECK_PATH}`;

const STROKE_WIDTH = 20;

// One accent colour per style, driving the stroke draw-in, the glow bloom,
// and the light sweep uniformly — the exact same three things mint already
// drove, just recoloured. 'monochrome' is `null` here, not a colour: it's
// handled as its own special case wherever this is read (currentColor for
// the stroke/glow, the page's own --bg-base token for the sheen) so it
// stays genuinely colour-free rather than "grey painted as a colour".
const ACCENT_PALETTES: Record<AccentStyle, string | null> = {
  monochrome: null,
  mint: '#9ee5b5',
  christmas: '#d1453b',
  halloween: '#f0883e',
  new_year: '#e0b354',
  valentines: '#ec6f8e',
  easter: '#b39ddb',
};

// Small decorative accents, one per seasonal style — never touching the
// mark's own geometry (BORDER/S_TOP/S_BOTTOM/CHECK above are completely
// unchanged by any of this). Each is hand-authored from simple primitives
// and checked by rendering it standalone, then at the loader's actual
// small display size, before being trusted here.
//
// Every style is one accessory WORN on the mark (a hat, a bow, ears —
// deliberately overlapping its top edge) plus, for a few styles, one or
// two small extra accents floating beside it. An earlier version tried
// floating-only decorations for every style (a pumpkin, fireworks, hearts,
// eggs, all off to the side) — those read as small, disconnected
// afterthoughts next to the mark rather than the mark actually being
// "dressed up", so only Christmas's worn hat landed. Coordinates for the
// floating extras intentionally range outside the mark's own
// 0–1536 / 0–1024 viewBox (negative, or past 1536) — the wrapping <svg> is
// `overflow-visible`, so they render as companions beside the mark rather
// than squeezed inside its bounding box. 'mint'/'monochrome' render
// nothing.
const HEART_PATH =
  'M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z';

// `data-firework` + an explicit CSS transform-origin at the burst's own
// centre (not the default "50% 50% of this element's bounding box", which
// for a sparse radiating shape sits somewhere unintuitive) — the GSAP
// effect scales each burst from 0 to simulate it "exploding" outward from
// its centre, and repeats that on a stagger during idle for a genuinely
// animated fireworks display, not a static asterisk sitting on the page.
function fireworkBurst(key: string, cx: number, cy: number, rIn: number, rOut: number, spokes: number, spokeWidth: number, color: string, rotation = 0) {
  const parts: React.ReactNode[] = [];
  for (let i = 0; i < spokes; i++) {
    const a = ((Math.PI * 2) / spokes) * i;
    const x1 = cx + rIn * Math.cos(a);
    const y1 = cy + rIn * Math.sin(a);
    const x2 = cx + rOut * Math.cos(a);
    const y2 = cy + rOut * Math.sin(a);
    parts.push(<line key={`s${i}`} x1={x1} y1={y1} x2={x2} y2={y2} stroke={color} strokeWidth={spokeWidth} strokeLinecap="round" />);
    parts.push(<circle key={`c${i}`} cx={x2} cy={y2} r={spokeWidth * 0.9} fill={color} />);
  }
  parts.push(<circle key="center" cx={cx} cy={cy} r={spokeWidth * 1.1} fill={color} />);
  return (
    <g key={key} data-firework transform={`rotate(${rotation} ${cx} ${cy})`} style={{ transformOrigin: `${cx}px ${cy}px` }}>
      {parts}
    </g>
  );
}

function decoration(accent: AccentStyle, color: string | null) {
  switch (accent) {
    case 'christmas':
      return (
        <>
          <path
            d="M 490 15 C 520 -150 600 -280 700 -280 C 850 -280 1050 -200 1080 -30 C 1090 30 1020 65 960 45 C 900 25 850 10 750 12 C 650 14 550 14 490 15 Z"
            fill={color!}
          />
          <ellipse cx={680} cy={10} rx={210} ry={45} fill="white" />
          <circle cx={985} cy={55} r={46} fill="white" />
        </>
      );
    // A restrained eclipse composition with small bat silhouettes.
    case 'halloween':
      return (
        <>
          {/* An eclipse, not a costume. The offset dark disc cuts a sharp
              crescent while three small bats move as a single silhouette. */}
          <circle cx={1165} cy={50} r={150} fill={color!} opacity={0.9} />
          <circle cx={1115} cy={12} r={150} fill="var(--bg-base)" />
          <g fill="#3d3560">
            <path d="M 150 190 Q 205 125 260 190 Q 205 160 150 190 Z" />
            <path d="M 1280 410 Q 1330 352 1380 410 Q 1330 382 1280 410 Z" />
            <path d="M 80 760 Q 122 710 164 760 Q 122 735 80 760 Z" />
          </g>
        </>
      );
    // A dense sky of varied fireworks with staggered scales and rotations.
    case 'new_year': {
      return (
        <>
          {fireworkBurst('fw1', 140, 120, 24, 118, 12, 8, '#e0b354', 4)}
          {fireworkBurst('fw2', 1375, 110, 18, 94, 10, 7, '#ec6f8e', 18)}
          {fireworkBurst('fw3', 90, 520, 16, 74, 9, 6, '#72b9d3', 8)}
          {fireworkBurst('fw4', 1450, 520, 22, 108, 12, 7, '#f0d68a', 15)}
          {fireworkBurst('fw5', 190, 925, 18, 88, 10, 7, '#9a82d1', 2)}
          {fireworkBurst('fw6', 1350, 900, 20, 104, 11, 7, '#e0b354', 11)}
          {fireworkBurst('fw7', 430, -85, 14, 70, 8, 6, '#ffffff', 22)}
          {fireworkBurst('fw8', 1090, -65, 18, 82, 9, 6, '#72b9d3', 5)}
          {fireworkBurst('fw9', 405, 1110, 13, 62, 8, 5, '#ec6f8e', 13)}
          {fireworkBurst('fw10', 1115, 1090, 16, 76, 9, 6, '#ffffff', 24)}
          {fireworkBurst('fw11', 315, 360, 10, 48, 7, 5, '#f0d68a', 9)}
          {fireworkBurst('fw12', 1230, 365, 12, 54, 8, 5, '#9a82d1', 20)}
          {fireworkBurst('fw13', 325, 720, 11, 52, 7, 5, '#72b9d3', 16)}
          {fireworkBurst('fw14', 1215, 730, 10, 48, 7, 5, '#ec6f8e', 3)}
          {fireworkBurst('fw15', 760, -185, 20, 105, 13, 7, color!, 7)}
        </>
      );
    }
    // One Cupid arrow visibly pierces the mark from corner to corner.
    case 'valentines':
      return (
        <>
          <line x1={105} y1={965} x2={1378} y2={78} stroke="#d6a24a" strokeWidth={18} strokeLinecap="round" />
          {/* Split feather fletching at the tail. */}
          <path d="M 105 965 L 238 950 L 170 918 Z" fill={color!} />
          <path d="M 105 965 L 143 835 L 171 918 Z" fill="#c9436a" />
          <path d="M 124 951 L 178 913" stroke="var(--bg-base)" strokeWidth={7} strokeLinecap="round" />
          {/* A heart-shaped Cupid point sits beyond the top-right corner,
              making the shaft read as an arrow rather than a diagonal line. */}
          <path d={HEART_PATH} fill={color!} stroke="#c9436a" strokeWidth={1.4} transform="translate(1374 82) rotate(55) scale(7.2) translate(-12 -12)" />
        </>
      );
    // A sparse spring bloom built from the mark's own rounded geometry.
    case 'easter': {
      return (
        <>
          {/* Spring shown as a sparse bloom. The petals share the logo's
              rounded geometry instead of adding bunny-ear clip art. */}
          <g transform="translate(1240 185)" opacity={0.9}>
            <ellipse cx={0} cy={-78} rx={42} ry={78} fill={color!} />
            <ellipse cx={78} cy={0} rx={78} ry={42} fill="#9ee5b5" />
            <ellipse cx={0} cy={78} rx={42} ry={78} fill="#f4c9dd" />
            <ellipse cx={-78} cy={0} rx={78} ry={42} fill="#e0b354" opacity={0.75} />
            <circle cx={0} cy={0} r={32} fill="var(--bg-base)" />
          </g>
          <path d="M 180 840 Q 300 675 430 820" fill="none" stroke="#9ee5b5" strokeWidth={18} strokeLinecap="round" />
          <circle cx={180} cy={840} r={25} fill="#f4c9dd" />
          <circle cx={430} cy={820} r={25} fill={color!} />
        </>
      );
    }
    default:
      return null;
  }
}

/**
 * Falls back to 'monochrome' for anything that isn't a recognised
 * AccentStyle — the fetch still being pending (`undefined`), a failed
 * request, or a stored value from a future/rolled-back deploy this build
 * doesn't know about. Never guesses at an unknown style.
 */
function resolveAccentStyle(value: string | undefined): AccentStyle {
  return (ACCENT_STYLES as readonly string[]).includes(value ?? '') ? (value as AccentStyle) : 'monochrome';
}

function strokeProps(accent: AccentStyle) {
  const color = ACCENT_PALETTES[accent];
  return {
    fillRule: 'evenodd' as const,
    fill: 'currentColor',
    stroke: color ?? 'currentColor',
    strokeWidth: STROKE_WIDTH,
    // `butt`, not `round` — a zero-length stroke-dasharray segment still
    // paints a full round cap right at its start point even with nothing
    // "drawn" yet, which is exactly what showed up as small dots sitting
    // inside the mark before the S/checkmark pieces began their own
    // reveal. Butt caps paint nothing for a zero-length dash, so there's
    // nothing to see until a piece actually starts drawing.
    strokeLinecap: 'butt' as const,
    strokeLinejoin: 'round' as const,
  };
}

/**
 * The global branded loading screen — shown by the admin/app/dashboard
 * layouts while auth/session state is still resolving (see
 * useAuthSplash). Visually, ONLY the SureSign mark: no spinner, no
 * wordmark, no message, no progress indicator.
 *
 * GSAP owns the whole animation (no Remotion — SVG paths plus native
 * stroke-dasharray/dashoffset are well within what GSAP alone can do; a
 * frame-based video-composition runtime would be pure overengineering
 * here):
 * Draws in one of several accents — see `ACCENT_STYLES` — controlled by
 * Super Admin/Admin under Branding settings (`loader_accent_style` on
 * `suresign_settings`) and read from the public `/guest-settings` endpoint;
 * see the `accent` prop below. 'monochrome' is the platform default.
 *   1. Entrance — the loader container fades/scales in, with a soft glow
 *      blooming in behind it (mint, or a neutral currentColor halo in
 *      monochrome).
 *   2. Assembly — the mark's four real pieces draw themselves in,
 *      one after another (border ring, then the S's two curls, then the
 *      checkmark last, as the "confirming" stroke) rather than one flat
 *      sweep, then crossfade together into the solid, final-coloured mark,
 *      with a single soft light sweep passing across it once.
 *   3. Idle — while still loading, an extremely restrained glow/opacity
 *      breathing loop (no rotation/bounce/pulse of the mark itself).
 *   4. Exit — triggered by the `exiting` prop once the app is actually
 *      ready; a brief fade/scale-out. The timeline never blocks or delays
 *      that readiness signal in either direction.
 */
export default function SureSignLoader({ exiting = false, loop = false, accent: accentOverride, fullScreen = true }: SureSignLoaderProps) {
  // Only fetched when the caller didn't explicitly force a style (real app
  // usage) — PreviewPanel's test buttons always pass an explicit value, so
  // this network call is skipped there entirely.
  const { data: loaderAccentStyle } = useQuery({
    queryKey: ['guest-settings', 'loader-accent-style'],
    queryFn: () => api.get('/guest-settings').then((r) => r.data?.data?.loader_accent_style as string | undefined),
    enabled: accentOverride === undefined,
    staleTime: 5 * 60 * 1000,
  });
  const accent: AccentStyle = accentOverride ?? resolveAccentStyle(loaderAccentStyle);
  const monochrome = accent === 'monochrome';
  const accentColor = ACCENT_PALETTES[accent];
  const decorationContent = decoration(accent, accentColor);

  const containerRef = useRef<HTMLDivElement>(null);
  const markGroupRef = useRef<SVGGElement>(null);
  const glowRef = useRef<SVGCircleElement>(null);
  const sheenRef = useRef<SVGRectElement>(null);
  const decorationGroupRef = useRef<SVGGElement>(null);
  const borderRef = useRef<SVGPathElement>(null);
  const sTopRef = useRef<SVGPathElement>(null);
  const sBottomRef = useRef<SVGPathElement>(null);
  const checkRef = useRef<SVGPathElement>(null);
  const idleTweenRef = useRef<gsap.core.Tween | null>(null);
  const idleDecorationTweenRef = useRef<gsap.core.Tween | null>(null);
  const idleFireworksTweenRef = useRef<gsap.core.Timeline | null>(null);
  const clipId = useId();
  const sheenId = useId();
  const glowFilterId = useId();

  useEffect(() => {
    const container = containerRef.current;
    const markGroup = markGroupRef.current;
    const glow = glowRef.current;
    const sheen = sheenRef.current;
    // Only present for the 5 seasonal styles (see `decoration()`) —
    // 'mint'/'monochrome' render no <g>, so this is optional throughout.
    const decorationGroup = decorationGroupRef.current;
    const pieces = [borderRef.current, sTopRef.current, sBottomRef.current, checkRef.current];
    if (!container || !markGroup || !glow || !sheen || pieces.some((p) => !p)) return;
    const [border, sTop, sBottom, check] = pieces as SVGPathElement[];

    if (prefersReducedMotion()) {
      // Static final mark, centered — no draw, no sweep, no loop. Any
      // seasonal decoration shows fully settled too, just never animated.
      gsap.set(container, { opacity: 1, scale: 1 });
      gsap.set(markGroup, { scale: 1 });
      gsap.set([border, sTop, sBottom, check], { fillOpacity: 1, strokeOpacity: 0 });
      gsap.set(glow, { opacity: 0 });
      gsap.set(sheen, { opacity: 0 });
      if (decorationGroup) gsap.set(decorationGroup, { opacity: 1, scale: 1, y: 0 });
      return;
    }

    const lengths = [border, sTop, sBottom, check].map((p) => p.getTotalLength());

    const tl = gsap.timeline({ repeat: loop ? -1 : 0, repeatDelay: loop ? 1.2 : 0 });

    tl.set(container, { opacity: 0, scale: 0.94 })
      .set(markGroup, { scale: 1, transformOrigin: '50% 50%' })
      .set(glow, { opacity: 0, scale: 0.7, transformOrigin: '50% 50%' })
      .set(sheen, { opacity: 0, x: -1536 })
      // Reset each piece to its own undrawn state — part of the timeline
      // itself (not a one-off gsap.set() before it) so `loop` mode's
      // tl.repeat(-1) re-applies these cleanly on every pass.
      .set(border, { strokeDasharray: lengths[0], strokeDashoffset: lengths[0], fillOpacity: 0, strokeOpacity: 1 })
      .set(sTop, { strokeDasharray: lengths[1], strokeDashoffset: lengths[1], fillOpacity: 0, strokeOpacity: 1 })
      .set(sBottom, { strokeDasharray: lengths[2], strokeDashoffset: lengths[2], fillOpacity: 0, strokeOpacity: 1 })
      .set(check, { strokeDasharray: lengths[3], strokeDashoffset: lengths[3], fillOpacity: 0, strokeOpacity: 1 });
    if (decorationGroup) tl.set(decorationGroup, { opacity: 0, scale: 0.6, y: 0, transformOrigin: '50% 50%' });
    tl
      // 1. Entrance, with the glow starting to bloom alongside it — a soft
      // dark halo in currentColor for monochrome (still no hue, just
      // depth) instead of skipping it outright.
      .to(container, { opacity: 1, scale: 1, duration: 0.4, ease: 'power2.out' })
      .to(glow, { opacity: monochrome ? 0.22 : 0.6, scale: 1, duration: 0.7, ease: 'power2.out' }, '<')
      // 2. Assembly — border ring first, then the S's two curls (slightly
      // overlapping each other for fluidity), then the checkmark last as
      // its own deliberate, slightly slower "confirming" stroke.
      .to(border, { strokeDashoffset: 0, duration: 0.5, ease: 'power1.inOut' }, '-=0.25')
      .to(sTop, { strokeDashoffset: 0, duration: 0.35, ease: 'power1.inOut' }, '-=0.05')
      .to(sBottom, { strokeDashoffset: 0, duration: 0.35, ease: 'power1.inOut' }, '-=0.2')
      .to(check, { strokeDashoffset: 0, duration: 0.5, ease: 'power2.inOut' }, '+=0.08')
      // 3. Resolve — all four pieces crossfade to the solid mark together,
      // the glow settles back, one light sweep passes through (a neutral
      // glint in monochrome, using the page's own background token so it
      // reads correctly against either a dark or light mark), and the
      // whole mark gives a small, restrained settle-pop as it finishes —
      // it "lands" rather than just stopping.
      .to([border, sTop, sBottom, check], { fillOpacity: 1, strokeOpacity: 0, duration: 0.35, ease: 'power2.out' }, '-=0.1')
      .to(glow, { opacity: monochrome ? 0.12 : 0.35, duration: 0.6, ease: 'power2.out' }, '<')
      .set(sheen, { opacity: 1 }, '<')
      .to(sheen, { x: 1536, duration: 0.7, ease: 'power1.inOut' }, '<')
      .to(sheen, { opacity: 0, duration: 0.25 }, '-=0.2')
      .to(markGroup, { scale: 1.045, duration: 0.16, ease: 'power2.out' }, '-=0.3')
      .to(markGroup, { scale: 1, duration: 0.28, ease: 'power2.out' });

    // 4. The seasonal decoration (if any) pops in right as the mark
    // settles — a bit more playful (back.out) than the mark's own
    // restrained easing is deliberate: this is the "fun extra", not the
    // brand mark itself.
    if (decorationGroup) {
      tl.to(decorationGroup, { opacity: 1, scale: 1, duration: 0.5, ease: 'back.out(1.6)' }, '-=0.35');
    }

    // 5. New Year only. Every burst starts as an ignition point, expands,
    // then burns out completely. Overlapping their timings creates an
    // actual show rather than revealing a sheet of static star shapes.
    if (decorationGroup) {
      const fireworks = Array.from(decorationGroup.querySelectorAll<SVGGElement>('[data-firework]'));

      if (fireworks.length) {
        tl.addLabel('fireworksShow', '-=0.2').set(fireworks, { scale: 0.04, opacity: 0 }, 'fireworksShow');
        fireworks.forEach((firework, index) => {
          const delay = index * 0.11;
          const ignition = `fireworksShow+=${delay.toFixed(2)}`;
          tl.to(firework, { scale: 1, opacity: 1, duration: 0.3, ease: 'power3.out' }, ignition)
            .to(firework, { scale: 1.38, opacity: 0, duration: 0.58, ease: 'power2.in' }, `fireworksShow+=${(delay + 0.24).toFixed(2)}`);
        });
      }
    }

    if (!loop) {
      tl.call(() => {
        idleTweenRef.current = gsap.to(glow, {
          opacity: monochrome ? 0.2 : 0.5,
          duration: 2.2,
          ease: 'sine.inOut',
          repeat: -1,
          yoyo: true,
        });
        if (decorationGroup) {
          idleDecorationTweenRef.current = gsap.to(decorationGroup, {
            y: -14,
            duration: 2.4,
            ease: 'sine.inOut',
            repeat: -1,
            yoyo: true,
          });
          const fireworks = Array.from(decorationGroup.querySelectorAll<SVGGElement>('[data-firework]'));
          if (fireworks.length) {
            const show = gsap.timeline({
              repeat: -1,
              repeatDelay: 0.25,
              onRepeat: () => gsap.set(fireworks, { scale: 0.04, opacity: 0 }),
            });
            show.set(fireworks, { scale: 0.04, opacity: 0 });
            fireworks.forEach((firework, index) => {
              // Two alternating rhythms stop the bursts reading like a
              // mechanical left-to-right cascade on each repeat.
              const ignition = ((index * 7) % fireworks.length) * 0.13;
              show.to(firework, { scale: 1, opacity: 1, duration: 0.28, ease: 'power3.out' }, ignition)
                .to(firework, { scale: 1.42, opacity: 0, duration: 0.62, ease: 'power2.in' }, ignition + 0.22);
            });
            idleFireworksTweenRef.current = show;
          }
        }
      });
    }

    return () => {
      tl.kill();
      idleTweenRef.current?.kill();
      idleDecorationTweenRef.current?.kill();
      idleFireworksTweenRef.current?.kill();
    };
  }, [loop, accent, monochrome]);

  useEffect(() => {
    const container = containerRef.current;
    if (!container || !exiting || prefersReducedMotion()) return;

    idleTweenRef.current?.kill();
    idleDecorationTweenRef.current?.kill();
    idleFireworksTweenRef.current?.kill();
    gsap.to(container, {
      opacity: 0,
      scale: 1.02,
      duration: 0.2,
      ease: 'power1.in',
      overwrite: true,
    });
  }, [exiting]);

  return (
    <div
      className={`${fullScreen ? 'min-h-screen' : 'h-full'} flex items-center justify-center`}
      style={{ backgroundColor: 'var(--bg-base)' }}
      role="status"
      aria-live="polite"
    >
      <span className="sr-only">Loading SureSign</span>
      <div
        ref={containerRef}
        className="relative w-24 h-24 sm:w-32 sm:h-32 md:w-40 md:h-40"
        style={{ color: 'var(--text-primary)' }}
      >
        <svg viewBox="0 0 1536 1024" className="absolute inset-0 h-full w-full overflow-visible" aria-hidden="true">
          <defs>
            <clipPath id={clipId}>
              <path d={FULL_PATH} fillRule="evenodd" />
            </clipPath>
            {/*
             * Monochrome's sheen uses the page's own --bg-base token rather
             * than a fixed colour — that token is near-white against the
             * dark mark in light mode and near-black against the (inverted)
             * light mark in dark mode, so the "glint" reads correctly in
             * both themes without hardcoding either. style= (not the plain
             * attribute) so the CSS custom property actually resolves.
             */}
            <linearGradient id={sheenId} x1="0" y1="0" x2="0.7" y2="1">
              <stop offset="0%" stopOpacity="0" style={{ stopColor: accentColor ?? 'var(--bg-base)' }} />
              <stop offset="50%" stopOpacity="0.85" style={{ stopColor: accentColor ?? 'var(--bg-base)' }} />
              <stop offset="100%" stopOpacity="0" style={{ stopColor: accentColor ?? 'var(--bg-base)' }} />
            </linearGradient>
            {/*
             * A CSS `filter: blur(Npx)` operates in real screen pixels —
             * on a circle living inside a viewBox that gets scaled down to
             * a ~100-160px box, a 70px blur is enormous relative to the
             * artwork and blows the glow out into a flat, opaque wash
             * instead of a soft graduated bloom. stdDeviation on a native
             * SVG filter is specified in the SAME user-space units as the
             * artwork itself, so it scales proportionally with it —
             * correct at any display size.
             */}
            <filter id={glowFilterId} x="-50%" y="-50%" width="200%" height="200%">
              <feGaussianBlur stdDeviation={60} />
            </filter>
          </defs>

          {/* Soft ambient glow bloom, sitting behind the mark — the theme's
              accent colour, or a soft currentColor halo (depth, no hue) in
              monochrome. Never on top of/blended with the mark itself
              either way. */}
          <circle ref={glowRef} cx={768} cy={512} r={300} fill={accentColor ?? 'currentColor'} filter={`url(#${glowFilterId})`} />

          <g ref={markGroupRef}>
            <path ref={borderRef} d={BORDER_PATH} {...strokeProps(accent)} />
            <path ref={sTopRef} d={S_TOP_PATH} {...strokeProps(accent)} />
            <path ref={sBottomRef} d={S_BOTTOM_PATH} {...strokeProps(accent)} />
            <path ref={checkRef} d={CHECK_PATH} {...strokeProps(accent)} />
          </g>

          {/* One light sweep through the finished mark, clipped to its
              exact silhouette so it never spills outside it. */}
          <g clipPath={`url(#${clipId})`}>
            <rect ref={sheenRef} y={0} width={1536} height={1024} fill={`url(#${sheenId})`} />
          </g>

          {/* Seasonal decoration, if any — floats around the mark rather
              than inside its bounding box (see `decoration()`'s own
              comment). Only rendered at all for the 5 themed styles, so
              `decorationGroupRef` stays null (and every GSAP guard above
              skips cleanly) for 'mint'/'monochrome'. */}
          {decorationContent && <g ref={decorationGroupRef}>{decorationContent}</g>}
        </svg>
      </div>
    </div>
  );
}
