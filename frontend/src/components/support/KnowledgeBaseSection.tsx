'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useQuery } from '@tanstack/react-query';
import { BookOpen, Search, ExternalLink, LifeBuoy } from 'lucide-react';
import api from '@/lib/api';

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

interface KBResponse {
  data: KBCategory[];
  suggested_categories: string[];
}

/** Public User Guide index — search/categories are a curated subset of docs.suresigncontracts.app, not a live mirror of it. */
export function KnowledgeBaseSection({ initialRoute }: { initialRoute?: string }) {
  const [search, setSearch] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['knowledge-base', search, initialRoute],
    queryFn: () =>
      api.get('/knowledge-base', { params: { q: search || undefined, route: initialRoute } }).then(r => r.data as KBResponse),
  });

  const suggested = data?.suggested_categories ?? [];
  const suggestedCategories = data?.data.filter(c => suggested.includes(c.category)) ?? [];
  const otherCategories = data?.data.filter(c => !suggested.includes(c.category)) ?? [];

  return (
    <div id="knowledge-base" className="rounded-2xl overflow-hidden scroll-mt-6" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      <div className="px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
        <h2 className="text-sm font-semibold flex items-center gap-1.5" style={{ color: 'var(--text-primary)' }}>
          <BookOpen size={14} />
          Knowledge Base
        </h2>
        <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Search the SureSign User Guide.</p>
      </div>

      <div className="p-5 space-y-4">
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search the user guide…"
            aria-label="Search the knowledge base"
            className="w-full pl-9 pr-4 py-2.5 rounded-xl text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
          />
        </div>

        {isLoading ? (
          <div className="space-y-2">
            {[...Array(3)].map((_, i) => <div key={i} className="h-10 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />)}
          </div>
        ) : !data?.data.length ? (
          <div className="py-8 text-center">
            <BookOpen size={22} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No results for &quot;{search}&quot;.</p>
          </div>
        ) : (
          <div className="space-y-4">
            {suggestedCategories.length > 0 && (
              <div>
                <p className="text-xs font-semibold uppercase tracking-wider mb-2" style={{ color: 'var(--gold)' }}>Suggested for you</p>
                <CategoryList categories={suggestedCategories} />
              </div>
            )}
            {otherCategories.length > 0 && (
              <div>
                {suggestedCategories.length > 0 && (
                  <p className="text-xs font-semibold uppercase tracking-wider mb-2" style={{ color: 'var(--text-muted)' }}>All topics</p>
                )}
                <CategoryList categories={otherCategories} />
              </div>
            )}
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
    </div>
  );
}

function CategoryList({ categories }: { categories: KBCategory[] }) {
  return (
    <div className="grid sm:grid-cols-2 gap-3">
      {categories.map(cat => (
        <div key={cat.category} className="rounded-xl p-3" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
          <p className="text-xs font-semibold mb-1.5" style={{ color: 'var(--text-primary)' }}>{cat.label}</p>
          <ul className="space-y-1">
            {cat.articles.map(article => (
              <li key={article.url}>
                <a
                  href={article.url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="group flex items-start gap-1.5 text-xs hover:opacity-80"
                  style={{ color: 'var(--text-secondary)' }}
                  title={article.summary}
                >
                  <ExternalLink size={11} className="mt-0.5 flex-shrink-0 opacity-60" />
                  <span className="truncate">{article.title}</span>
                </a>
              </li>
            ))}
          </ul>
        </div>
      ))}
    </div>
  );
}
