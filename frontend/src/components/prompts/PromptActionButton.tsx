'use client';

/**
 * PromptActionButton — Drop-in button that opens PromptContextModal.
 *
 * Usage:
 *   <PromptActionButton
 *     label="Prompt"
 *     module="Variations"
 *     recordType="variation"
 *     recordId={variation.id}
 *     projectId={projectId}
 *   />
 */

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { BookOpen } from 'lucide-react';
import api from '@/lib/api';
import PromptContextModal from './PromptContextModal';

export interface PromptActionButtonProps {
  /** Button label text */
  label?: string;
  /** Module name used to pre-filter prompts (e.g. "Variations") */
  module?: string;
  /** Record type for context (e.g. "variation") */
  recordType?: string;
  /** Record ID for context */
  recordId?: number;
  /** Project ID — locks context to this project */
  projectId?: number | string | null;
  /** Pre-select a specific template slug (currently unused — opens modal in module-filtered state) */
  suggestedPromptSlug?: string;
  /** Visual style variant */
  variant?: 'icon-only' | 'compact' | 'full';
  className?: string;
}

export default function PromptActionButton({
  label = 'Prompt',
  module,
  recordType,
  recordId,
  projectId,
  variant = 'compact',
  className = '',
}: PromptActionButtonProps) {
  const [open, setOpen] = useState(false);

  const { data: settingsData } = useQuery({
    queryKey: ['suresign-settings-public'],
    queryFn: () => api.get('/admin/suresign-settings').then(r => r.data?.data ?? {}),
    staleTime: 5 * 60 * 1000,
  });

  if ((settingsData as any)?.prompts_enabled === false) {
    return null;
  }

  const buttonContent = variant === 'icon-only' ? (
    <BookOpen size={13} />
  ) : (
    <>
      <BookOpen size={12} />
      {label}
    </>
  );

  return (
    <>
      <button
        onClick={e => { e.stopPropagation(); setOpen(true); }}
        title={label}
        className={`flex items-center gap-1 transition-colors hover:bg-[var(--bg-hover)] rounded-lg ${
          variant === 'icon-only' ? 'p-1.5' : 'text-xs px-2 py-1'
        } ${className}`}
        style={{ color: 'var(--gold)' }}
      >
        {buttonContent}
      </button>

      {open && (
        <PromptContextModal
          module={module}
          recordType={recordType}
          recordId={recordId}
          projectId={projectId}
          projectLocked={!!projectId}
          adminRoute={false}
          onClose={() => setOpen(false)}
        />
      )}
    </>
  );
}
