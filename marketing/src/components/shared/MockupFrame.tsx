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
 * (a real screenshot dropped in later won't either).
 */
export function MockupFrame({ children, caption, className = '', annotations = [], elevated = false }: MockupFrameProps) {
  return (
    <figure className={`relative ${className}`} style={elevated ? { perspective: '1600px' } : undefined}>
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
            ? 'shadow-[var(--shadow-deep)] [transform:rotateX(1.5deg)_rotateY(-1.5deg)] hover:[transform:translateY(-4px)_rotateX(0deg)_rotateY(0deg)]'
            : 'shadow-[var(--shadow-pop)]'
        }`}
      >
        <div className="flex items-center gap-1.5 border-b border-[#e4e4e4] bg-[#f4f4f4] px-4 py-3">
          <span className="h-2.5 w-2.5 rounded-full bg-[#d4d4d4]" />
          <span className="h-2.5 w-2.5 rounded-full bg-[#d4d4d4]" />
          <span className="h-2.5 w-2.5 rounded-full bg-[#d4d4d4]" />
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
          className="absolute -bottom-3 left-[10%] right-[10%] h-6 rounded-full bg-black/10 blur-2xl"
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

      {caption ? <figcaption className="mt-4 text-sm text-text-muted">{caption}</figcaption> : null}
    </figure>
  );
}
