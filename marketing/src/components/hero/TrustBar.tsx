const POINTS = [
  'Built for UK construction contract administration',
  'Supports JCT, NEC and FIDIC workflows',
  'Secure, organisation-scoped document handling',
  'Automated contract analysis, confirmed before use',
];

function PointList({ ariaHidden }: { ariaHidden?: boolean }) {
  return (
    <div className="flex shrink-0 items-center gap-x-3 pr-3" aria-hidden={ariaHidden}>
      {POINTS.map((point, i) => (
        <span key={point} className="flex items-center gap-x-3 whitespace-nowrap text-xs text-text-muted">
          {point}
          {i < POINTS.length - 1 && <span className="text-border-light">·</span>}
        </span>
      ))}
      <span className="text-border-light">·</span>
    </div>
  );
}

export function TrustBar() {
  return (
    <div className="overflow-hidden py-6">
      <div className="flex w-max animate-[marquee_28s_linear_infinite]">
        <PointList />
        <PointList ariaHidden />
      </div>
    </div>
  );
}
