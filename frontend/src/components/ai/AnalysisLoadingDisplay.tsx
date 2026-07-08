'use client';

import { useEffect, useState } from 'react';
import { Sparkles } from 'lucide-react';

const DEFAULT_MESSAGES = [
  { at: 0,   text: 'Reading document…' },
  { at: 8,   text: 'Extracting key commercial terms…' },
  { at: 18,  text: 'Identifying payment conditions…' },
  { at: 30,  text: 'Analysing obligations and risks…' },
  { at: 45,  text: 'Cross-referencing clauses…' },
  { at: 60,  text: 'Almost there, finalising results…' },
  { at: 80,  text: 'Nearly done, just a few more seconds…' },
  { at: 100, text: 'Wrapping up the analysis…' },
];

export default function AnalysisLoadingDisplay({
  messages = DEFAULT_MESSAGES,
  caption = 'AI is reading your document. You can minimise this and come back.',
}: {
  messages?: { at: number; text: string }[];
  caption?: string;
}) {
  const [elapsed, setElapsed] = useState(0);
  // Simulate progress: fast early, slow later, never quite reaches 100
  const progress = Math.min(97, Math.round(100 * (1 - Math.exp(-elapsed / 90))));
  const message = [...messages].reverse().find(m => elapsed >= m.at)?.text ?? messages[0].text;

  useEffect(() => {
    const t = setInterval(() => setElapsed(s => s + 1), 1000);
    return () => clearInterval(t);
  }, []);

  return (
    <div className="flex flex-col items-center justify-center py-16 gap-5 select-none">
      {/* Pulsing ring + icon */}
      <div className="relative flex items-center justify-center">
        <div className="absolute w-20 h-20 rounded-full animate-ping opacity-20" style={{ backgroundColor: 'var(--gold)' }} />
        <div className="relative w-16 h-16 rounded-full flex items-center justify-center" style={{ backgroundColor: 'var(--gold-15)', border: '2px solid var(--gold)' }}>
          <Sparkles size={26} style={{ color: 'var(--gold)' }} />
        </div>
      </div>

      {/* Message */}
      <div className="text-center space-y-1">
        <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{message}</p>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{caption}</p>
      </div>

      {/* Progress bar */}
      <div className="w-64 space-y-1.5">
        <div className="w-full h-1.5 rounded-full overflow-hidden" style={{ backgroundColor: 'var(--bg-elevated)' }}>
          <div
            className="h-full rounded-full transition-all duration-1000 ease-out"
            style={{ width: `${progress}%`, backgroundColor: 'var(--gold)' }}
          />
        </div>
        <div className="flex justify-center text-xs" style={{ color: 'var(--text-muted)' }}>
          <span>{progress}%</span>
        </div>
      </div>
    </div>
  );
}
