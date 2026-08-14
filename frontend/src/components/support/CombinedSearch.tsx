'use client';

import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Link from 'next/link';
import { Search, BookOpen, HelpCircle, ExternalLink } from 'lucide-react';
import api from '@/lib/api';
import { searchFaq } from '@/lib/faq';

interface KBArticle {
  title: string;
  summary: string;
  url: string;
}

interface KBCategory {
  category: string;
  label: string;
  articles: KBArticle[];
}

const MAX_RESULTS_PER_TYPE = 6;

// Searches Knowledge Base articles (server-side, /knowledge-base?q=) and FAQ
// entries (client-side, the same FAQ_CATEGORIES the dedicated FAQ page uses)
// in parallel, and renders them as two clearly-labeled result groups rather
// than merging them — they're different kinds of answer (an external user
// guide article vs. an in-app short answer) and conflating them would just
// make results harder to scan.
export function CombinedSearch() {
  const [query, setQuery] = useState('');
  const trimmed = query.trim();

  const { data: kbCategories, isFetching: kbLoading } = useQuery({
    queryKey: ['help-search-kb', trimmed],
    queryFn: () => api.get('/knowledge-base', { params: { q: trimmed } }).then(r => (r.data?.data ?? []) as KBCategory[]),
    enabled: trimmed.length > 0,
  });

  const faqResults = useMemo(() => {
    if (!trimmed) return [];
    return searchFaq(trimmed).flatMap(cat => cat.items.map(item => ({ ...item, categoryLabel: cat.label })));
  }, [trimmed]);

  const kbResults = useMemo(
    () => (kbCategories ?? []).flatMap(cat => cat.articles.map(a => ({ ...a, categoryLabel: cat.label }))),
    [kbCategories],
  );

  const hasQuery = trimmed.length > 0;
  const hasResults = kbResults.length > 0 || faqResults.length > 0;

  return (
    <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      <div className="p-5 space-y-4">
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={query}
            onChange={e => setQuery(e.target.value)}
            placeholder="Search the knowledge base and frequently asked questions..."
            aria-label="Search Help Center"
            className="w-full rounded-xl py-3 pl-9 pr-4 text-sm outline-none transition-all duration-200 focus:border-[var(--gold)] focus:ring-2 focus:ring-[var(--gold)]/10"
            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
          />
        </div>

        {hasQuery && (
          !hasResults && !kbLoading ? (
            <p className="text-sm text-center py-4" style={{ color: 'var(--text-muted)' }}>No results for &quot;{trimmed}&quot;.</p>
          ) : (
            <div className="grid sm:grid-cols-2 gap-4">
              {faqResults.length > 0 && (
                <div>
                  <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider mb-2" style={{ color: 'var(--text-muted)' }}>
                    <HelpCircle size={11} /> FAQ
                  </p>
                  <div className="space-y-1.5">
                    {faqResults.slice(0, MAX_RESULTS_PER_TYPE).map(r => (
                      <Link
                        key={r.q}
                        href={`/app/help/faq?q=${encodeURIComponent(r.q)}`}
                        className="block rounded-lg px-3 py-2 transition-colors hover:bg-[var(--bg-hover)]"
                        style={{ border: '1px solid var(--border)' }}
                      >
                        <p className="text-xs font-medium truncate" style={{ color: 'var(--text-primary)' }}>{r.q}</p>
                        <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{r.categoryLabel}</p>
                      </Link>
                    ))}
                  </div>
                </div>
              )}

              {(kbResults.length > 0 || kbLoading) && (
                <div>
                  <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider mb-2" style={{ color: 'var(--text-muted)' }}>
                    <BookOpen size={11} /> Knowledge Base
                  </p>
                  <div className="space-y-1.5">
                    {kbLoading ? (
                      [...Array(2)].map((_, i) => <div key={i} className="h-11 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />)
                    ) : (
                      kbResults.slice(0, MAX_RESULTS_PER_TYPE).map(a => (
                        <a
                          key={a.url}
                          href={a.url}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="flex items-start gap-1.5 rounded-lg px-3 py-2 transition-colors hover:bg-[var(--bg-hover)]"
                          style={{ border: '1px solid var(--border)' }}
                          title={a.summary}
                        >
                          <ExternalLink size={11} className="mt-0.5 flex-shrink-0 opacity-60" />
                          <span className="min-w-0">
                            <p className="text-xs font-medium truncate" style={{ color: 'var(--text-primary)' }}>{a.title}</p>
                            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{a.categoryLabel}</p>
                          </span>
                        </a>
                      ))
                    )}
                  </div>
                </div>
              )}
            </div>
          )
        )}
      </div>
    </div>
  );
}
