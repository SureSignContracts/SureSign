'use client';

import { useMemo, useState } from 'react';
import { useSearchParams } from 'next/navigation';
import Link from 'next/link';
import { Search, ChevronDown, HelpCircle, LifeBuoy } from 'lucide-react';
import { FaqItem, searchFaq } from '@/lib/faq';

function AccordionItem({ item, defaultOpen }: { item: FaqItem; defaultOpen?: boolean }) {
  const [open, setOpen] = useState(!!defaultOpen);
  return (
    <div style={{ borderBottom: '1px solid var(--border)' }}>
      <button
        onClick={() => setOpen(o => !o)}
        className="w-full flex items-center justify-between gap-3 px-5 py-3.5 text-left transition-colors hover:bg-[var(--bg-hover)]"
      >
        <span className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{item.q}</span>
        <ChevronDown
          size={15}
          className="flex-shrink-0 transition-transform duration-200"
          style={{ color: 'var(--text-muted)', transform: open ? 'rotate(180deg)' : 'rotate(0deg)' }}
        />
      </button>
      {open && (
        <div className="px-5 pb-4 text-sm leading-relaxed" style={{ color: 'var(--text-secondary)' }}>
          {item.a}
        </div>
      )}
    </div>
  );
}

export default function FaqPage() {
  const searchParams = useSearchParams();
  // Seeded once from ?q= (e.g. the Help Center landing page's combined
  // search linking a specific question here) — after that it's a normal,
  // freely-editable search box, not synced back to the URL on every keystroke.
  const [search, setSearch] = useState(searchParams.get('q') ?? '');

  const filteredCategories = useMemo(() => searchFaq(search), [search]);
  const isExactSingleMatch = filteredCategories.length === 1 && filteredCategories[0].items.length === 1;

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6">
      {/* Header */}
      <div className="flex items-start gap-3">
        <div className="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" style={{ backgroundColor: 'var(--gold-15)' }}>
          <HelpCircle size={20} style={{ color: 'var(--gold)' }} />
        </div>
        <div>
          <h1 className="text-[1.75rem] font-bold" style={{ color: 'var(--text-primary)' }}>FAQ</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
            Answers to common questions about running projects in SureSign, by category.
          </p>
        </div>
      </div>

      {/* Search */}
      <div className="relative">
        <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
        <input
          value={search}
          onChange={e => setSearch(e.target.value)}
          placeholder="Search the FAQ…"
          className="w-full pl-9 pr-4 py-2.5 rounded-xl text-sm outline-none"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)', boxShadow: 'var(--shadow-card)' }}
        />
      </div>

      {/* Categories */}
      {filteredCategories.length === 0 ? (
        <div className="rounded-2xl p-8 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No results for &quot;{search}&quot;.</p>
        </div>
      ) : (
        <div className="space-y-4">
          {filteredCategories.map(cat => (
            <div key={cat.key} className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
              <div className="flex items-center gap-2.5 px-5 py-3.5" style={{ borderBottom: '1px solid var(--border)', backgroundColor: 'var(--bg-elevated)' }}>
                <cat.icon size={15} style={{ color: 'var(--text-secondary)' }} />
                <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{cat.label}</h2>
              </div>
              <div>
                {cat.items.map(item => (
                  <AccordionItem key={item.q} item={item} defaultOpen={isExactSingleMatch} />
                ))}
              </div>
            </div>
          ))}
        </div>
      )}

      <div className="flex items-center justify-between pt-2" style={{ borderTop: '1px solid var(--border)' }}>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Still need help?</p>
        <Link
          href="/app/help/support"
          className="flex items-center gap-1.5 text-xs font-medium hover:opacity-80"
          style={{ color: 'var(--gold)' }}
        >
          <LifeBuoy size={12} />
          Contact Support
        </Link>
      </div>
    </div>
  );
}

