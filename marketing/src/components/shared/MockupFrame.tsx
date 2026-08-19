interface Annotation {
  label: string;
  position?: 'top-right' | 'bottom-left' | 'bottom-right';
}

interface MockupFrameProps {
  children: React.ReactNode;
  caption?: string;
  className?: string;
  annotations?: Annotation[];
  elevated?: boolean;
  /**
   * Whether the elevated variant's static 3D tilt (rotateX/rotateY) is
   * applied. Defaults to true — fine for the CSS-drawn placeholders, whose
   * "pixels" are just DOM borders/text that rotate cleanly. A raster
   * screenshot rotated the same way visibly blurs (the browser has to
   * resample a bitmap at an angle, most noticeable on small UI text) — pass
   * `tilt={false}` for a real <Image>, which keeps the bigger elevated
   * offset/shadow but settles the frame flat.
   */
  tilt?: boolean;
}

const POSITION_CLASSES: Record<NonNullable<Annotation['position']>, string> = {
  'top-right': '-top-4 -right-4 md:-top-5 md:-right-8',
  'bottom-left': '-bottom-4 -left-4 md:-bottom-5 md:-left-8',
  'bottom-right': '-bottom-4 -right-4 md:-bottom-5 md:-right-8',
};

/**
 * Premium presentation shell for product screens — browser chrome, layered
 * depth (an offset back-card plus the frame itself), a soft ambient shadow
 * standing in for a reflection, a faint top glass sheen, a barely-there
 * static tilt that settles flat on hover, and optional floating annotation
 * chips. Ships with CSS-built placeholder screens (see
 * components/shared/placeholders) until real app screenshots are captured
 * against prepared demo data — swap the placeholder children for a plain
 * <Image> of the real export at that point, this frame does not need to
 * change.
 *
 * Colours here are hardcoded to light values, not the --bg-base/--text-*
 * theme tokens — this frame represents a real product screenshot, which
 * doesn't invert when the marketing site's own theme toggle flips to dark
 * (a real screenshot dropped in later won't either). The chrome bar is
 * hardcoded to the app's forest/mint identity (`#18211d`/`#9ee5b5`, the same
 * pair `ProjectModuleHeader` uses for every in-app module banner) rather
 * than a neutral grey, so every mockup reads as the current product, not the
 * pre-rebrand one.
 */
export function MockupFrame({ children, caption, className = '', annotations = [], elevated = false, tilt = true }: MockupFrameProps) {
  return (
    <figure className={className}>
      {/*
       * Everything positioned (the offset back-card, the ambient ground
       * shadow, the floating annotation chips) lives inside this dedicated
       * wrapper rather than directly on <figure>. `inset-0`/`absolute`
       * always resolve against THIS box, which is sized to the frame alone
       * — never against <figure>'s own box, which also includes the
       * figcaption below. Previously the back-card's `inset-0` stretched
       * across the figcaption's height too (a static element sharing the
       * figure with positioned siblings paints first regardless of DOM
       * order), so the offset card visually covered the caption text.
       */}
      <div className="relative" style={elevated ? { perspective: '1600px' } : undefined}>
        {/* Layered back-card — pure depth cue, no content, offset behind the frame. */}
        <div
          aria-hidden
          className={`absolute inset-0 rounded-2xl border border-[#e4e4e4] bg-[#f4f4f4] ${
            elevated ? 'translate-x-3 translate-y-3 md:translate-x-5 md:translate-y-5' : 'translate-x-1.5 translate-y-1.5'
          }`}
        />

        <div
          className={`group relative overflow-hidden rounded-2xl border border-[#e4e4e4] bg-white transition-transform duration-500 ease-out ${
            elevated
              ? `shadow-[var(--shadow-deep)] ${
                  tilt
                    ? '[transform:rotateX(1.5deg)_rotateY(-1.5deg)] hover:[transform:translateY(-4px)_rotateX(0deg)_rotateY(0deg)]'
                    : 'hover:-translate-y-1'
                }`
              : 'shadow-[var(--shadow-pop)]'
          }`}
        >
          <div className="flex items-center justify-between gap-4 border-b border-[#0d130f] bg-[#18211d] px-4 py-2.5">
            <span className="flex items-center gap-2 text-[11px] font-medium tracking-tight text-white">
              <span className="h-1.5 w-1.5 rounded-full bg-[#9ee5b5]" />
              SureSign workspace
            </span>
            <span className="font-mono text-[10px] text-white/45">Project record</span>
          </div>
          <div className="relative">
            {children}
            {/* Faint top sheen — a glass cue, not a full glassmorphism treatment. */}
            <div
              aria-hidden
              className="pointer-events-none absolute inset-x-0 top-0 h-16 bg-gradient-to-b from-white/[0.06] to-transparent"
            />
          </div>
        </div>

        {/* Ambient ground shadow — reads as reflected light, not a literal mirror. */}
        {elevated && (
          <div
            aria-hidden
            className="absolute -bottom-3 left-[10%] right-[10%] h-6 rounded-full bg-[#121212]/10 blur-2xl"
          />
        )}

        {annotations.map((a, i) => (
          <div
            key={a.label}
            className={`absolute z-10 hidden rounded-full border border-[#e4e4e4] bg-white/90 px-4 py-2 text-xs font-medium text-[#0a0a0a] shadow-[var(--shadow-card)] backdrop-blur md:block ${
              POSITION_CLASSES[a.position ?? 'top-right']
            }`}
            style={{ transitionDelay: `${i * 60}ms` }}
          >
            {a.label}
          </div>
        ))}
      </div>

      {/*
       * mt-8, not mt-4 — the offset back-card (and, when elevated, the
       * ground shadow) deliberately peeks out past the frame's own bottom
       * edge for the layered-depth effect, up to 20px on an elevated
       * desktop frame. mt-4 (16px) left the caption's cap-height sitting
       * right at that peek, reading as "too close"/nearly touching even
       * though the text itself wasn't clipped. mt-8 clears the deepest
       * peek with real margin to spare, elevated or not.
       */}
      {caption ? <figcaption className="mt-8 text-sm text-text-muted">{caption}</figcaption> : null}
    </figure>
  );
}
