'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { CheckCircle, XCircle, Sparkles, Minus } from 'lucide-react';
import { useAiAnalysisStore } from '@/store/aiAnalysisStore';
import api from '@/lib/api';

export default function AiAnalysisWidget() {
  const { isMinimized, status, contractTitle, projectId, analysisId, updateStatus, clear } = useAiAnalysisStore();
  const router = useRouter();

  // When minimized and still processing, poll independently so widget stays accurate
  useEffect(() => {
    if (!isMinimized || !analysisId) return;
    if (status !== 'pending' && status !== 'processing') return;

    const interval = setInterval(async () => {
      try {
        const res = await api.get(`/ai/analyses/${analysisId}`);
        const a = res.data?.data;
        if (a?.status && a.status !== status) {
          updateStatus(a.status, a.status === 'completed' ? a : null);
        }
      } catch {
        // Silent — user can return to contracts page to retry
      }
    }, 4000);

    return () => clearInterval(interval);
  }, [isMinimized, analysisId, status, updateStatus]);

  if (!isMinimized || !status || status === 'cancelled') return null;

  const isProcessing = status === 'pending' || status === 'processing';
  const isComplete   = status === 'completed' || status === 'confirmed';
  const isFailed     = status === 'failed';

  function handleClick() {
    if (projectId) {
      router.push(`/app/projects/${projectId}/contracts`);
    }
    useAiAnalysisStore.getState().restore();
  }

  function handleDismiss(e: React.MouseEvent) {
    e.stopPropagation();
    clear();
  }

  return (
    <div
      className="fixed bottom-6 right-6 z-50 flex items-center gap-3 rounded-2xl shadow-xl px-4 py-3 cursor-pointer transition-all hover:scale-[1.02] active:scale-[0.98]"
      style={{
        backgroundColor: 'var(--bg-surface)',
        border: `1.5px solid ${isComplete ? 'rgba(74,222,128,0.4)' : isFailed ? 'rgba(248,113,113,0.4)' : 'rgba(185,149,102,0.35)'}`,
        boxShadow: '0 8px 32px rgba(0,0,0,0.18), 0 2px 8px rgba(0,0,0,0.08)',
        minWidth: 220,
      }}
      onClick={handleClick}
      role="button"
      tabIndex={0}
      onKeyDown={(e) => e.key === 'Enter' && handleClick()}
    >
      {/* Circular indicator */}
      <div className="relative flex-shrink-0 w-9 h-9">
        {isProcessing && (
          <>
            {/* Track */}
            <svg className="absolute inset-0 w-9 h-9" viewBox="0 0 36 36">
              <circle cx="18" cy="18" r="15" fill="none" strokeWidth="2.5" stroke="var(--border)" />
            </svg>
            {/* Spinning arc */}
            <svg className="absolute inset-0 w-9 h-9 animate-spin" viewBox="0 0 36 36" style={{ animationDuration: '1.4s' }}>
              <circle
                cx="18" cy="18" r="15" fill="none" strokeWidth="2.5"
                stroke="var(--gold)"
                strokeDasharray="28 66"
                strokeLinecap="round"
                strokeDashoffset="0"
              />
            </svg>
            <Sparkles
              size={13}
              className="absolute inset-0 m-auto"
              style={{ color: 'var(--gold)' }}
            />
          </>
        )}
        {isComplete && (
          <div className="w-9 h-9 rounded-full flex items-center justify-center" style={{ backgroundColor: 'rgba(74,222,128,0.15)' }}>
            <CheckCircle size={20} style={{ color: '#4ade80' }} />
          </div>
        )}
        {isFailed && (
          <div className="w-9 h-9 rounded-full flex items-center justify-center" style={{ backgroundColor: 'rgba(248,113,113,0.15)' }}>
            <XCircle size={20} style={{ color: '#f87171' }} />
          </div>
        )}
      </div>

      {/* Text */}
      <div className="flex-1 min-w-0">
        <p className="text-xs font-semibold leading-tight" style={{ color: 'var(--text-primary)' }}>
          {isProcessing ? 'Analysing contract…' : isComplete ? 'Analysis complete' : 'Analysis failed'}
        </p>
        <p className="text-[11px] mt-0.5 truncate leading-tight" style={{ color: 'var(--text-muted)', maxWidth: 140 }}>
          {contractTitle || 'Contract'}
        </p>
        {(isComplete || isFailed) && (
          <p className="text-[10px] mt-1 font-medium" style={{ color: isComplete ? '#4ade80' : '#f87171' }}>
            {isComplete ? 'Click to review →' : 'Click to view details →'}
          </p>
        )}
      </div>

      {/* Dismiss (×) */}
      <button
        onClick={handleDismiss}
        className="flex-shrink-0 p-1 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
        title="Dismiss"
      >
        <Minus size={12} style={{ color: 'var(--text-muted)' }} />
      </button>
    </div>
  );
}
