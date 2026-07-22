const STAGES = ['Choose Time', 'Details', 'Review', 'Confirmation'] as const;

export type BookingStage = typeof STAGES[number];

export function BookingProgress({ current }: { current: BookingStage }) {
  const currentIndex = STAGES.indexOf(current);

  return (
    <div aria-label="Booking progress" className="flex items-center gap-2">
      {STAGES.map((stage, i) => (
        <div key={stage} className="flex flex-1 items-center gap-2 last:flex-initial">
          <div className="flex items-center gap-1.5">
            <span
              aria-current={i === currentIndex ? 'step' : undefined}
              className={`h-1.5 w-1.5 rounded-full transition-colors duration-300 ${
                i <= currentIndex ? 'bg-text-primary' : 'bg-border'
              }`}
            />
            <span
              className={`hidden text-xs font-medium transition-colors duration-300 sm:inline ${
                i === currentIndex ? 'text-text-primary' : i < currentIndex ? 'text-text-secondary' : 'text-text-muted'
              }`}
            >
              {stage}
            </span>
          </div>
          {i < STAGES.length - 1 && (
            <span className="h-px flex-1 bg-border" aria-hidden="true">
              <span
                className="block h-px bg-text-primary transition-all duration-500 ease-out"
                style={{ width: i < currentIndex ? '100%' : '0%' }}
              />
            </span>
          )}
        </div>
      ))}
    </div>
  );
}
