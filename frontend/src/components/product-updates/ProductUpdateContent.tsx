'use client';

import { ArrowRight } from 'lucide-react';
import Link from 'next/link';
import type { ProductUpdate } from '@/lib/productUpdates';
import { CATEGORY_LABELS, CATEGORY_ICONS, CATEGORY_STYLES, isSafeProductUpdateUrl } from '@/lib/productUpdates';

/**
 * The single presentation of one Product Update's category/title/summary/
 * content/CTA — shared by WhatsNewModal, the /app/whats-new history page,
 * and the Super Admin editor's own preview, so all three always render a
 * published update identically (per spec: "prefer reuse ... rather than
 * building a separate preview rendering implementation").
 */
export default function ProductUpdateContent({ update, dense = false }: { update: ProductUpdate; dense?: boolean }) {
  const Icon = CATEGORY_ICONS[update.category];
  const style = CATEGORY_STYLES[update.category];
  const ctaSafe = !!update.cta_url && isSafeProductUpdateUrl(update.cta_url);
  const isInternal = ctaSafe && update.cta_url!.startsWith('/');

  return (
    <div className="space-y-3">
      <span
        className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold"
        style={{ backgroundColor: style.bg, color: style.text }}
      >
        <Icon size={12} />
        {CATEGORY_LABELS[update.category]}
      </span>
      <h3 className={dense ? 'text-base font-semibold' : 'text-lg font-semibold'} style={{ color: 'var(--text-primary)' }}>
        {update.title}
      </h3>
      <p className="text-sm font-medium" style={{ color: 'var(--text-secondary)' }}>
        {update.summary}
      </p>
      <p className="text-sm whitespace-pre-wrap" style={{ color: 'var(--text-muted)' }}>
        {update.content}
      </p>
      {ctaSafe && update.cta_label && (
        isInternal ? (
          <Link
            href={update.cta_url!}
            className="inline-flex items-center gap-1.5 text-sm font-medium"
            style={{ color: 'var(--gold)' }}
          >
            {update.cta_label}
            <ArrowRight size={14} />
          </Link>
        ) : (
          <a
            href={update.cta_url!}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1.5 text-sm font-medium"
            style={{ color: 'var(--gold)' }}
          >
            {update.cta_label}
            <ArrowRight size={14} />
          </a>
        )
      )}
    </div>
  );
}
