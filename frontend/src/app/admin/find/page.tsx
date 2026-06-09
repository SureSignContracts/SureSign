'use client';

import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import {
  AlertCircle, ArrowUpRight, Building2, CalendarDays, CheckCircle2,
  ChevronLeft, ChevronRight, FileText, Hash, Key,
  Loader2, MapPin, Search, User, Users, X, XCircle,
} from 'lucide-react';
import api from '@/lib/api';

// ── Types ────────────────────────────────────────────────────────────────────

type CompanyResult = {
  company_number: string;
  title: string;
  company_status: string;
  company_type: string;
  date_of_creation: string | null;
  address_snippet: string;
};

type CompanyDetail = {
  company_number: string;
  company_name: string;
  company_status: string;
  company_type: string;
  date_of_creation: string | null;
  address_formatted: string;
  registered_office_address: Record<string, string>;
  sic_codes: string[];
  accounts: {
    next_due?: string;
    next_made_up_to?: string;
    last_accounts?: { made_up_to?: string; period_end_on?: string };
  } | null;
  confirmation_statement: {
    next_due?: string;
    last_made_up_to?: string;
    next_made_up_to?: string;
  } | null;
  has_insolvency_history: boolean;
  jurisdiction: string | null;
};

type Officer = {
  name: string;
  officer_role: string;
  appointed_on: string | null;
  resigned_on: string | null;
  nationality: string | null;
  occupation: string | null;
};

type SearchResponse = {
  total_results: number;
  items_per_page: number;
  start_index: number;
  items: CompanyResult[];
  error?: string;
  message?: string;
};

const PAGE_SIZE = 20;

// ── Helpers ──────────────────────────────────────────────────────────────────

const STATUS_STYLES: Record<string, { bg: string; text: string; dot: string }> = {
  active:      { bg: 'rgba(34,197,94,0.1)',   text: '#4ade80', dot: '#4ade80' },
  dissolved:   { bg: 'rgba(239,68,68,0.1)',   text: '#f87171', dot: '#f87171' },
  liquidation: { bg: 'rgba(245,158,11,0.1)',  text: '#fbbf24', dot: '#fbbf24' },
};

function statusStyle(status: string) {
  return STATUS_STYLES[status?.toLowerCase()] ?? { bg: 'rgba(90,86,82,0.18)', text: '#9a9490', dot: '#9a9490' };
}

function StatusBadge({ status }: { status: string }) {
  const s = statusStyle(status);
  const Icon = status?.toLowerCase() === 'active' ? CheckCircle2
    : status?.toLowerCase() === 'dissolved' ? XCircle : AlertCircle;
  return (
    <span
      className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium capitalize"
      style={{ backgroundColor: s.bg, color: s.text }}
    >
      <Icon size={11} />
      {status || 'unknown'}
    </span>
  );
}

function formatType(type: string): string {
  return (type ?? '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

function formatRole(role: string): string {
  return (role ?? '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

function fmtDate(iso: string | null | undefined): string {
  if (!iso) return '—';
  const [y, m, d] = iso.split('-');
  return `${d}/${m}/${y}`;
}

function DetailRow({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex justify-between gap-4 py-2.5" style={{ borderBottom: '1px solid var(--border)' }}>
      <span className="text-xs flex-shrink-0 w-40" style={{ color: 'var(--text-muted)' }}>{label}</span>
      <span className="text-xs text-right" style={{ color: 'var(--text-primary)' }}>{value || '—'}</span>
    </div>
  );
}

// ── Company detail panel ─────────────────────────────────────────────────────

function CompanyPanel({ companyNumber, onClose }: { companyNumber: string; onClose: () => void }) {
  const [tab, setTab] = useState<'overview' | 'people'>('overview');

  const { data: detail, isLoading: detailLoading, isError: detailError } = useQuery({
    queryKey: ['ch-company', companyNumber],
    queryFn: () => api.get<CompanyDetail>(`/admin/companies-house/${companyNumber}`).then(r => r.data),
    staleTime: 5 * 60 * 1000,
  });

  const { data: officersData, isLoading: officersLoading } = useQuery({
    queryKey: ['ch-officers', companyNumber],
    queryFn: () => api.get<{ total_results: number; items: Officer[] }>(`/admin/companies-house/${companyNumber}/officers`).then(r => r.data),
    enabled: tab === 'people',
    staleTime: 5 * 60 * 1000,
  });

  const active = detail?.company_status?.toLowerCase() === 'active';
  const officers = officersData?.items ?? [];
  const activeOfficers = officers.filter(o => !o.resigned_on);
  const resignedOfficers = officers.filter(o => o.resigned_on);

  return (
    <div className="fixed inset-0 z-50 flex" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }} onClick={onClose}>
      {/* Slide-over panel */}
      <div
        className="ml-auto h-full w-full max-w-lg flex flex-col overflow-hidden shadow-2xl"
        style={{ backgroundColor: 'var(--bg-surface)' }}
        onClick={e => e.stopPropagation()}
      >
        {/* Header */}
        <div className="flex items-start justify-between gap-3 px-5 py-4 flex-shrink-0" style={{ borderBottom: '1px solid var(--border)' }}>
          <div className="min-w-0">
            {detailLoading ? (
              <div className="h-5 w-48 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
            ) : (
              <>
                <p className="text-sm font-bold leading-snug" style={{ color: 'var(--text-primary)' }}>
                  {detail?.company_name || companyNumber}
                </p>
                <div className="mt-1.5 flex items-center gap-2 flex-wrap">
                  <span className="flex items-center gap-1 text-xs font-mono" style={{ color: 'var(--text-muted)' }}>
                    <Hash size={10} /> {companyNumber}
                  </span>
                  {detail && <StatusBadge status={detail.company_status} />}
                </div>
              </>
            )}
          </div>
          <div className="flex items-center gap-2 flex-shrink-0">
            <a
              href={`https://find-and-update.company-information.service.gov.uk/company/${companyNumber}`}
              target="_blank"
              rel="noopener noreferrer"
              title="Open on Companies House"
              className="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs transition-colors hover:opacity-80"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
            >
              <ArrowUpRight size={12} />
              CH
            </a>
            <button onClick={onClose} className="rounded-lg p-1.5 hover:bg-[var(--bg-elevated)] transition-colors">
              <X size={16} style={{ color: 'var(--text-muted)' }} />
            </button>
          </div>
        </div>

        {/* Tabs */}
        <div className="flex flex-shrink-0 px-5" style={{ borderBottom: '1px solid var(--border)' }}>
          {(['overview', 'people'] as const).map(t => (
            <button
              key={t}
              onClick={() => setTab(t)}
              className="px-1 py-3 mr-5 text-xs font-medium capitalize transition-colors"
              style={{
                color: tab === t ? 'var(--gold)' : 'var(--text-muted)',
                borderBottom: tab === t ? '2px solid var(--gold)' : '2px solid transparent',
              }}
            >
              {t === 'overview' ? <span className="flex items-center gap-1.5"><FileText size={12} />Overview</span>
                : <span className="flex items-center gap-1.5"><Users size={12} />People</span>}
            </button>
          ))}
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto px-5 py-4">
          {detailError && (
            <div className="rounded-xl p-4 text-center text-sm" style={{ color: 'var(--text-muted)' }}>
              Could not load company details.
            </div>
          )}

          {/* ── OVERVIEW TAB ── */}
          {tab === 'overview' && (
            <div className="space-y-5">
              {detailLoading ? (
                <div className="space-y-2">
                  {[...Array(8)].map((_, i) => <div key={i} className="h-8 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />)}
                </div>
              ) : detail ? (
                <>
                  {/* Registered office */}
                  <div>
                    <p className="text-xs font-semibold mb-1" style={{ color: 'var(--text-muted)' }}>REGISTERED OFFICE ADDRESS</p>
                    <div className="rounded-xl p-3" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                      <p className="text-sm flex items-start gap-1.5" style={{ color: 'var(--text-primary)' }}>
                        <MapPin size={13} className="flex-shrink-0 mt-0.5" style={{ color: 'var(--gold)' }} />
                        {detail.address_formatted || '—'}
                      </p>
                    </div>
                  </div>

                  {/* Core details */}
                  <div>
                    <p className="text-xs font-semibold mb-1" style={{ color: 'var(--text-muted)' }}>COMPANY DETAILS</p>
                    <div className="rounded-xl overflow-hidden" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                      <DetailRow label="Company status" value={<StatusBadge status={detail.company_status} />} />
                      <DetailRow label="Company type" value={formatType(detail.company_type)} />
                      <DetailRow label="Incorporated on" value={fmtDate(detail.date_of_creation)} />
                      {detail.jurisdiction && <DetailRow label="Jurisdiction" value={detail.jurisdiction} />}
                      {detail.has_insolvency_history && (
                        <DetailRow label="Insolvency history" value={
                          <span style={{ color: '#f87171' }}>Yes</span>
                        } />
                      )}
                    </div>
                  </div>

                  {/* Accounts */}
                  {detail.accounts && (
                    <div>
                      <p className="text-xs font-semibold mb-1" style={{ color: 'var(--text-muted)' }}>ACCOUNTS</p>
                      <div className="rounded-xl overflow-hidden" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                        {detail.accounts.next_made_up_to && (
                          <DetailRow label="Next accounts made up to" value={fmtDate(detail.accounts.next_made_up_to)} />
                        )}
                        {detail.accounts.next_due && (
                          <DetailRow label="Due by" value={fmtDate(detail.accounts.next_due)} />
                        )}
                        {detail.accounts.last_accounts?.made_up_to && (
                          <DetailRow label="Last accounts made up to" value={fmtDate(detail.accounts.last_accounts.made_up_to)} />
                        )}
                        {detail.accounts.last_accounts?.period_end_on && (
                          <DetailRow label="Last accounts period end" value={fmtDate(detail.accounts.last_accounts.period_end_on)} />
                        )}
                      </div>
                    </div>
                  )}

                  {/* Confirmation statement */}
                  {detail.confirmation_statement && (
                    <div>
                      <p className="text-xs font-semibold mb-1" style={{ color: 'var(--text-muted)' }}>CONFIRMATION STATEMENT</p>
                      <div className="rounded-xl overflow-hidden" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                        {detail.confirmation_statement.next_made_up_to && (
                          <DetailRow label="Next statement date" value={fmtDate(detail.confirmation_statement.next_made_up_to)} />
                        )}
                        {detail.confirmation_statement.next_due && (
                          <DetailRow label="Due by" value={fmtDate(detail.confirmation_statement.next_due)} />
                        )}
                        {detail.confirmation_statement.last_made_up_to && (
                          <DetailRow label="Last statement dated" value={fmtDate(detail.confirmation_statement.last_made_up_to)} />
                        )}
                      </div>
                    </div>
                  )}

                  {/* SIC codes */}
                  {detail.sic_codes?.length > 0 && (
                    <div>
                      <p className="text-xs font-semibold mb-1" style={{ color: 'var(--text-muted)' }}>NATURE OF BUSINESS (SIC)</p>
                      <div className="rounded-xl p-3 space-y-1" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                        {detail.sic_codes.map(code => (
                          <p key={code} className="text-xs" style={{ color: 'var(--text-primary)' }}>{code}</p>
                        ))}
                      </div>
                    </div>
                  )}
                </>
              ) : null}
            </div>
          )}

          {/* ── PEOPLE TAB ── */}
          {tab === 'people' && (
            <div className="space-y-4">
              {officersLoading ? (
                <div className="space-y-2">
                  {[...Array(4)].map((_, i) => <div key={i} className="h-16 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />)}
                </div>
              ) : officers.length === 0 ? (
                <div className="py-12 text-center">
                  <Users size={24} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
                  <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No officers found</p>
                </div>
              ) : (
                <>
                  {activeOfficers.length > 0 && (
                    <div>
                      <p className="text-xs font-semibold mb-2" style={{ color: 'var(--text-muted)' }}>
                        ACTIVE — {activeOfficers.length}
                      </p>
                      <div className="space-y-2">
                        {activeOfficers.map((o, i) => (
                          <OfficerCard key={i} officer={o} />
                        ))}
                      </div>
                    </div>
                  )}
                  {resignedOfficers.length > 0 && (
                    <div>
                      <p className="text-xs font-semibold mb-2 mt-4" style={{ color: 'var(--text-muted)' }}>
                        RESIGNED — {resignedOfficers.length}
                      </p>
                      <div className="space-y-2">
                        {resignedOfficers.map((o, i) => (
                          <OfficerCard key={i} officer={o} resigned />
                        ))}
                      </div>
                    </div>
                  )}
                </>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function OfficerCard({ officer, resigned = false }: { officer: Officer; resigned?: boolean }) {
  return (
    <div
      className="flex items-start gap-3 rounded-xl p-3"
      style={{
        backgroundColor: 'var(--bg-elevated)',
        opacity: resigned ? 0.65 : 1,
      }}
    >
      <div
        className="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0"
        style={{ backgroundColor: resigned ? 'rgba(90,86,82,0.2)' : 'rgba(185,149,102,0.15)' }}
      >
        <User size={14} style={{ color: resigned ? 'var(--text-muted)' : 'var(--gold)' }} />
      </div>
      <div className="min-w-0">
        <p className="text-xs font-semibold" style={{ color: 'var(--text-primary)' }}>{officer.name}</p>
        <p className="text-xs mt-0.5" style={{ color: 'var(--gold)' }}>{formatRole(officer.officer_role)}</p>
        <div className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs" style={{ color: 'var(--text-muted)' }}>
          {officer.appointed_on && <span>Appointed {fmtDate(officer.appointed_on)}</span>}
          {officer.resigned_on && <span style={{ color: '#f87171' }}>Resigned {fmtDate(officer.resigned_on)}</span>}
          {officer.nationality && <span>{officer.nationality}</span>}
          {officer.occupation && <span>{officer.occupation}</span>}
        </div>
      </div>
    </div>
  );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function FindCompanyPage() {
  const [query, setQuery] = useState('');
  const [submittedQuery, setSubmittedQuery] = useState('');
  const [page, setPage] = useState(1);
  const [results, setResults] = useState<CompanyResult[] | null>(null);
  const [totalResults, setTotalResults] = useState(0);
  const [apiKeyMissing, setApiKeyMissing] = useState(false);
  const [viewingCompany, setViewingCompany] = useState<string | null>(null);

  const totalPages = Math.ceil(totalResults / PAGE_SIZE);

  const searchMutation = useMutation({
    mutationFn: async ({ q, p }: { q: string; p: number }) => {
      const res = await api.get<SearchResponse>('/admin/companies-house/search', {
        params: { q, limit: PAGE_SIZE, start_index: (p - 1) * PAGE_SIZE },
      });
      return res.data;
    },
    onSuccess: (data) => {
      if (data.error === 'no_api_key') {
        setApiKeyMissing(true);
        setResults(null);
      } else {
        setApiKeyMissing(false);
        setResults(data.items ?? []);
        setTotalResults(data.total_results ?? 0);
      }
    },
    onError: (err: { response?: { data?: SearchResponse } }) => {
      const data = err?.response?.data;
      if (data?.error === 'no_api_key') { setApiKeyMissing(true); setResults(null); }
      else { setResults([]); }
    },
  });

  const runSearch = (q: string, p: number) => {
    if (q.trim().length >= 2) searchMutation.mutate({ q: q.trim(), p });
  };

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const q = query.trim();
    if (q.length < 2) return;
    setSubmittedQuery(q);
    setPage(1);
    runSearch(q, 1);
  };

  const goToPage = (p: number) => {
    setPage(p);
    runSearch(submittedQuery, p);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const startNum = (page - 1) * PAGE_SIZE + 1;
  const endNum = Math.min(page * PAGE_SIZE, totalResults);

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Find Company</h1>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
          Search the UK Companies House register
        </p>
      </div>

      {/* API key warning */}
      {apiKeyMissing && (
        <div className="flex items-start gap-3 rounded-xl p-4"
          style={{ backgroundColor: 'rgba(239,68,68,0.08)', border: '1px solid rgba(239,68,68,0.25)' }}>
          <Key size={16} className="flex-shrink-0 mt-0.5" style={{ color: '#f87171' }} />
          <div>
            <p className="text-sm font-semibold" style={{ color: '#f87171' }}>API key not configured</p>
            <p className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>
              Get a free key at{' '}
              <a href="https://developer.company-information.service.gov.uk/manage-applications"
                target="_blank" rel="noopener noreferrer" className="underline" style={{ color: 'var(--gold)' }}>
                developer.company-information.service.gov.uk
              </a>
              , then set{' '}
              <code className="rounded px-1 py-0.5 text-xs" style={{ backgroundColor: 'var(--bg-elevated)' }}>COMPANIES_HOUSE_API_KEY</code>
              {' '}in <code className="rounded px-1 py-0.5 text-xs" style={{ backgroundColor: 'var(--bg-elevated)' }}>.env.docker</code>.
            </p>
          </div>
        </div>
      )}

      {/* Search bar */}
      <form onSubmit={handleSubmit}>
        <div className="flex gap-2">
          <div className="relative flex-1">
            <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"
              style={{ color: 'var(--text-muted)' }} />
            <input
              value={query}
              onChange={e => setQuery(e.target.value)}
              placeholder="Search by company name or number…"
              autoFocus
              className="w-full pl-10 pr-4 py-2.5 rounded-xl text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
          </div>
          <button
            type="submit"
            disabled={query.trim().length < 2 || searchMutation.isPending}
            className="flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-medium disabled:opacity-50"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            {searchMutation.isPending ? <Loader2 size={14} className="animate-spin" /> : <Search size={14} />}
            Search
          </button>
        </div>
      </form>

      {/* Loading */}
      {searchMutation.isPending && (
        <div className="space-y-2">
          {[...Array(5)].map((_, i) => (
            <div key={i} className="h-20 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))}
        </div>
      )}

      {/* Results */}
      {!searchMutation.isPending && results !== null && (
        <>
          {/* Result count + pagination info */}
          <div className="flex items-center justify-between">
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
              {results.length === 0
                ? 'No results found'
                : `Showing ${startNum}–${endNum} of ${totalResults.toLocaleString()} result${totalResults !== 1 ? 's' : ''}`}
            </p>
            {totalPages > 1 && (
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Page {page} of {totalPages}</p>
            )}
          </div>

          {results.length === 0 ? (
            <div className="rounded-2xl p-12 text-center"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
              <Building2 size={28} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
              <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>No companies found</p>
              <p className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>Try a different name or company number</p>
            </div>
          ) : (
            <>
              <div className="overflow-hidden rounded-2xl" style={{ border: '1px solid var(--border)' }}>
                {results.map((company, index) => {
                  const s = statusStyle(company.company_status);
                  return (
                    <div
                      key={company.company_number}
                      className="flex items-start justify-between gap-4 px-5 py-4 transition-colors hover:bg-[var(--bg-elevated)]"
                      style={{
                        backgroundColor: 'var(--bg-surface)',
                        borderBottom: index < results.length - 1 ? '1px solid var(--border)' : undefined,
                      }}
                    >
                      <div className="flex items-start gap-4 min-w-0">
                        <div className="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5"
                          style={{ backgroundColor: 'rgba(185,149,102,0.1)' }}>
                          <Building2 size={17} style={{ color: 'var(--gold)' }} />
                        </div>
                        <div className="min-w-0">
                          <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                            {company.title}
                          </p>
                          <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span className="flex items-center gap-1 text-xs font-mono" style={{ color: 'var(--text-muted)' }}>
                              <Hash size={11} />{company.company_number}
                            </span>
                            <span className="flex items-center gap-1 px-1.5 py-0.5 rounded-full text-xs font-medium capitalize"
                              style={{ backgroundColor: s.bg, color: s.text }}>
                              {company.company_status || 'unknown'}
                            </span>
                            {company.company_type && (
                              <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                                {formatType(company.company_type)}
                              </span>
                            )}
                            {company.date_of_creation && (
                              <span className="flex items-center gap-1 text-xs" style={{ color: 'var(--text-muted)' }}>
                                <CalendarDays size={11} />Inc. {fmtDate(company.date_of_creation)}
                              </span>
                            )}
                          </div>
                          {company.address_snippet && (
                            <p className="mt-1 flex items-start gap-1 text-xs" style={{ color: 'var(--text-muted)' }}>
                              <MapPin size={11} className="flex-shrink-0 mt-0.5" />
                              <span className="truncate">{company.address_snippet}</span>
                            </p>
                          )}
                        </div>
                      </div>

                      {/* Actions */}
                      <div className="flex items-center gap-1.5 flex-shrink-0">
                        <button
                          onClick={() => setViewingCompany(company.company_number)}
                          className="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors hover:opacity-80"
                          style={{ backgroundColor: 'rgba(185,149,102,0.15)', color: 'var(--gold)' }}
                        >
                          View
                        </button>
                        <a
                          href={`https://find-and-update.company-information.service.gov.uk/company/${company.company_number}`}
                          target="_blank"
                          rel="noopener noreferrer"
                          title="Open on Companies House"
                          className="flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors hover:opacity-80"
                          style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
                        >
                          <ArrowUpRight size={12} />
                          CH
                        </a>
                      </div>
                    </div>
                  );
                })}
              </div>

              {/* Pagination */}
              {totalPages > 1 && (
                <div className="flex items-center justify-between pt-1">
                  <button
                    onClick={() => goToPage(page - 1)}
                    disabled={page === 1 || searchMutation.isPending}
                    className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium disabled:opacity-40 transition-colors hover:bg-[var(--bg-elevated)]"
                    style={{ color: 'var(--text-secondary)' }}
                  >
                    <ChevronLeft size={14} /> Previous
                  </button>

                  <div className="flex items-center gap-1">
                    {Array.from({ length: Math.min(totalPages, 7) }, (_, i) => {
                      // Show first, last, current ±2, with ellipses
                      const p = i + 1;
                      const show = p === 1 || p === totalPages || Math.abs(p - page) <= 2;
                      const showEllipsis = !show && (p === 2 || p === totalPages - 1);
                      if (showEllipsis) return <span key={p} className="px-1 text-xs" style={{ color: 'var(--text-muted)' }}>…</span>;
                      if (!show) return null;
                      return (
                        <button
                          key={p}
                          onClick={() => goToPage(p)}
                          className="w-8 h-8 rounded-lg text-xs font-medium transition-colors"
                          style={{
                            backgroundColor: page === p ? 'var(--gold)' : 'transparent',
                            color: page === p ? 'var(--accent-fg)' : 'var(--text-secondary)',
                          }}
                        >
                          {p}
                        </button>
                      );
                    })}
                    {totalPages > 7 && (
                      // Full page range when there are many pages
                      <span className="text-xs ml-1" style={{ color: 'var(--text-muted)' }}>
                        of {totalPages}
                      </span>
                    )}
                  </div>

                  <button
                    onClick={() => goToPage(page + 1)}
                    disabled={page === totalPages || searchMutation.isPending}
                    className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium disabled:opacity-40 transition-colors hover:bg-[var(--bg-elevated)]"
                    style={{ color: 'var(--text-secondary)' }}
                  >
                    Next <ChevronRight size={14} />
                  </button>
                </div>
              )}
            </>
          )}
        </>
      )}

      {/* Empty state */}
      {results === null && !searchMutation.isPending && !apiKeyMissing && (
        <div className="rounded-2xl p-14 text-center"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          <div className="w-14 h-14 rounded-2xl flex items-center justify-center mx-auto mb-4"
            style={{ backgroundColor: 'rgba(185,149,102,0.1)' }}>
            <Search size={22} style={{ color: 'var(--gold)' }} />
          </div>
          <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Search the UK Companies Register</p>
          <p className="mt-1.5 text-xs max-w-xs mx-auto" style={{ color: 'var(--text-muted)' }}>
            Enter a company name or registration number to search Companies House
          </p>
          <a href="https://find-and-update.company-information.service.gov.uk/" target="_blank"
            rel="noopener noreferrer" className="inline-flex items-center gap-1 mt-4 text-xs"
            style={{ color: 'var(--gold)' }}>
            Open Companies House directly <ArrowUpRight size={12} />
          </a>
        </div>
      )}

      {/* Company detail panel */}
      {viewingCompany && (
        <CompanyPanel
          companyNumber={viewingCompany}
          onClose={() => setViewingCompany(null)}
        />
      )}
    </div>
  );
}
