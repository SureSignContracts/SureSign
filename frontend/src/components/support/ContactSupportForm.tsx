'use client';

import { useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { CheckCircle2, LifeBuoy, Send, X, Paperclip, Info } from 'lucide-react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { collectDiagnostics, parseRouteContext, SUPPORT_CATEGORIES } from '@/lib/supportContext';

const MAX_SCREENSHOT_BYTES = 5 * 1024 * 1024;
const ALLOWED_SCREENSHOT_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

// The actual Contact Support submission form — subject/category/message,
// screenshot, diagnostics and recent-activity opt-ins, route/module context.
// Shared between the dedicated Contact Support page (/app/help/support) and
// anywhere else that needs the real form, not a link to it. Extracted from
// the old embedded `/app/help` implementation in Help Hub V2 Batch 4 — no
// backend/workflow changes, this is the same component, just relocated.
export function ContactSupportForm({
  initialCategory,
  initialRoute,
  initialModule,
}: {
  initialCategory?: string;
  initialRoute?: string;
  initialModule?: string;
}) {
  const [subject, setSubject] = useState('');
  const [category, setCategory] = useState(
    initialCategory && SUPPORT_CATEGORIES.some(c => c.value === initialCategory) ? initialCategory : 'other'
  );
  const [message, setMessage] = useState('');
  const [contextRemoved, setContextRemoved] = useState(false);
  const [includeDiagnostics, setIncludeDiagnostics] = useState(false);
  const [showDiagnosticsInfo, setShowDiagnosticsInfo] = useState(false);
  const [includeRecentActivity, setIncludeRecentActivity] = useState(false);
  const [showActivityPreview, setShowActivityPreview] = useState(false);
  const [screenshot, setScreenshot] = useState<File | null>(null);
  const [screenshotPreview, setScreenshotPreview] = useState<string | null>(null);
  const [screenshotError, setScreenshotError] = useState('');
  const [reference, setReference] = useState('');
  const [error, setError] = useState('');
  const fileInputRef = useRef<HTMLInputElement>(null);

  const hasContext = Boolean(initialRoute) && !contextRemoved;

  // Resolved server-side (RecentActivityService) — this is only a preview
  // fetch of the same data store() would attach; the frontend never
  // constructs or sends the activity entries themselves, only the opt-in flag.
  const activityPreviewQuery = useQuery({
    queryKey: ['recent-activity-preview'],
    queryFn: () => api.get('/support-tickets/recent-activity-preview').then(r => r.data.data as { timestamp: string; project: string | null; description: string }[]),
    enabled: includeRecentActivity && showActivityPreview,
    staleTime: 60 * 1000,
  });

  function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    e.target.value = ''; // allow re-selecting the same file after removing it
    if (!file) return;

    if (!ALLOWED_SCREENSHOT_TYPES.includes(file.type)) {
      setScreenshotError('Only PNG, JPEG, or WebP images are supported.');
      return;
    }
    if (file.size > MAX_SCREENSHOT_BYTES) {
      setScreenshotError('Screenshots must be under 5 MB.');
      return;
    }

    setScreenshotError('');
    setScreenshot(file);
    setScreenshotPreview(URL.createObjectURL(file));
  }

  function removeScreenshot() {
    if (screenshotPreview) URL.revokeObjectURL(screenshotPreview);
    setScreenshot(null);
    setScreenshotPreview(null);
    setScreenshotError('');
  }

  const mutation = useMutation({
    mutationFn: () => {
      const fd = new FormData();
      fd.append('subject', subject);
      fd.append('category', category);
      fd.append('message', message);

      if (hasContext && initialRoute) {
        fd.append('route', initialRoute);
        if (initialModule) fd.append('module', initialModule);
        const { projectId, tradePackageId } = parseRouteContext(initialRoute);
        if (projectId) fd.append('project_id', String(projectId));
        if (tradePackageId) fd.append('trade_package_id', String(tradePackageId));
      }

      if (includeDiagnostics) {
        fd.append('include_diagnostics', '1');
        Object.entries(collectDiagnostics()).forEach(([key, value]) => fd.append(`diagnostics[${key}]`, String(value)));
      }

      if (includeRecentActivity) fd.append('include_recent_activity', '1');

      if (screenshot) fd.append('screenshot', screenshot);

      return api.post('/support-tickets', fd, { headers: { 'Content-Type': 'multipart/form-data' } });
    },
    onSuccess: (res) => {
      setReference(res.data?.data?.reference ?? '');
      setSubject('');
      setMessage('');
      setCategory('other');
      setIncludeDiagnostics(false);
      setIncludeRecentActivity(false);
      setShowActivityPreview(false);
      removeScreenshot();
    },
    onError: (err) => setError(getErrorMessage(err, 'Could not submit your request. Please try again.')),
  });

  return (
    <div id="contact-support" className="rounded-2xl overflow-hidden scroll-mt-6" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      <div className="px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
        <h2 className="text-sm font-semibold flex items-center gap-1.5" style={{ color: 'var(--text-primary)' }}>
          <LifeBuoy size={14} />
          Contact Support
        </h2>
        <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Can&apos;t find an answer in the Help Center? Send your question to the SureSign team.</p>
      </div>
      <div className="p-5 space-y-3">
        {reference ? (
          <div className="text-sm space-y-1" style={{ color: '#4ade80' }} role="status" aria-live="polite">
            <div className="flex items-center gap-2">
              <CheckCircle2 size={15} />
              Your request has been sent.
            </div>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Reference: <span className="font-mono" style={{ color: 'var(--text-secondary)' }}>{reference}</span></p>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>The SureSign support team will review the details provided.</p>
            <button
              onClick={() => setReference('')}
              className="text-xs font-medium mt-1"
              style={{ color: 'var(--gold)' }}
            >
              Send another request
            </button>
          </div>
        ) : (
          <>
            {error && <p className="text-xs" style={{ color: '#f87171' }} role="alert">{error}</p>}

            {hasContext && (
              <div
                className="flex items-center justify-between gap-2 px-3 py-2 rounded-lg text-xs"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
              >
                <span className="min-w-0 truncate">
                  Reporting from <strong>{initialModule || 'this page'}</strong>
                  <span style={{ color: 'var(--text-muted)' }}> — {initialRoute}</span>
                </span>
                <button
                  onClick={() => setContextRemoved(true)}
                  aria-label="Remove page context"
                  className="flex-shrink-0 p-0.5 rounded hover:opacity-70"
                  style={{ color: 'var(--text-muted)' }}
                >
                  <X size={12} />
                </button>
              </div>
            )}

            <select
              value={category}
              onChange={e => setCategory(e.target.value)}
              aria-label="Category"
              className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            >
              {SUPPORT_CATEGORIES.map(c => (
                <option key={c.value} value={c.value}>{c.label}</option>
              ))}
            </select>
            <input
              value={subject}
              onChange={e => setSubject(e.target.value)}
              placeholder="Subject"
              aria-label="Subject"
              className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
            <textarea
              value={message}
              onChange={e => setMessage(e.target.value)}
              placeholder="Describe your question or issue…"
              aria-label="Message"
              rows={4}
              className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none resize-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />

            {/* Screenshot upload */}
            <div className="space-y-1.5">
              {screenshotPreview ? (
                <div className="relative inline-block">
                  {/* eslint-disable-next-line @next/next/no-img-element -- local blob preview, next/image can't optimize it */}
                  <img
                    src={screenshotPreview}
                    alt="Screenshot preview"
                    className="rounded-lg max-h-32 block"
                    style={{ border: '1px solid var(--border)' }}
                  />
                  <button
                    onClick={removeScreenshot}
                    aria-label="Remove screenshot"
                    className="absolute -top-2 -right-2 w-5 h-5 rounded-full flex items-center justify-center"
                    style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
                  >
                    <X size={11} />
                  </button>
                </div>
              ) : (
                <label
                  className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium cursor-pointer transition-colors hover:bg-[var(--bg-hover)]"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
                >
                  <Paperclip size={12} />
                  Attach a screenshot
                  <input
                    ref={fileInputRef}
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    onChange={handleFileChange}
                    className="hidden"
                  />
                </label>
              )}
              {screenshotError && <p className="text-xs" style={{ color: '#f87171' }} role="alert">{screenshotError}</p>}
            </div>

            {/* Diagnostics opt-in */}
            <div className="space-y-1">
              <div className="flex items-center gap-3">
                <label className="flex items-center gap-2 text-xs cursor-pointer" style={{ color: 'var(--text-secondary)' }}>
                  <input
                    type="checkbox"
                    checked={includeDiagnostics}
                    onChange={e => setIncludeDiagnostics(e.target.checked)}
                    className="rounded"
                  />
                  Include technical diagnostics
                </label>
                <button
                  type="button"
                  onClick={() => setShowDiagnosticsInfo(v => !v)}
                  aria-expanded={showDiagnosticsInfo}
                  className="flex items-center gap-1 text-xs underline-offset-2 hover:underline"
                  style={{ color: 'var(--text-muted)' }}
                >
                  <Info size={11} />
                  What&apos;s included?
                </button>
              </div>
              {showDiagnosticsInfo && (
                <p className="text-xs rounded-lg p-2.5" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}>
                  Your browser, operating system, screen size, language, timezone, the SureSign app version, and the time you submit this request.
                  We never collect passwords, access tokens, cookies, or the contents of any page.
                </p>
              )}
            </div>

            {/* Recent activity opt-in */}
            <div className="space-y-1.5">
              <div className="flex items-center gap-3">
                <label className="flex items-center gap-2 text-xs cursor-pointer" style={{ color: 'var(--text-secondary)' }}>
                  <input
                    type="checkbox"
                    checked={includeRecentActivity}
                    onChange={e => { setIncludeRecentActivity(e.target.checked); if (!e.target.checked) setShowActivityPreview(false); }}
                    className="rounded"
                  />
                  Include recent SureSign activity
                </label>
                {includeRecentActivity && (
                  <button
                    type="button"
                    onClick={() => setShowActivityPreview(v => !v)}
                    aria-expanded={showActivityPreview}
                    className="flex items-center gap-1 text-xs underline-offset-2 hover:underline"
                    style={{ color: 'var(--text-muted)' }}
                  >
                    <Info size={11} />
                    {showActivityPreview ? 'Hide preview' : 'Preview what will be included'}
                  </button>
                )}
              </div>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                Up to the last 20 recent actions inside SureSign for your organization — never server logs, files, or message contents.
              </p>
              {includeRecentActivity && showActivityPreview && (
                <div className="rounded-lg p-2.5 space-y-1 max-h-40 overflow-y-auto" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                  {activityPreviewQuery.isLoading ? (
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Loading…</p>
                  ) : activityPreviewQuery.isError ? (
                    <p className="text-xs" style={{ color: '#f87171' }}>Could not load a preview right now.</p>
                  ) : !activityPreviewQuery.data?.length ? (
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No recent activity to include.</p>
                  ) : (
                    activityPreviewQuery.data.map((entry, i) => (
                      <p key={i} className="text-xs truncate" style={{ color: 'var(--text-secondary)' }}>
                        {entry.project ? <span style={{ color: 'var(--text-muted)' }}>[{entry.project}] </span> : null}
                        {entry.description}
                      </p>
                    ))
                  )}
                </div>
              )}
            </div>

            <div className="flex justify-end">
              <button
                onClick={() => mutation.mutate()}
                disabled={!subject.trim() || !message.trim() || mutation.isPending}
                className="flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-medium transition-opacity disabled:opacity-50 active:scale-[0.98]"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
              >
                <Send size={12} />
                {mutation.isPending ? 'Sending…' : 'Send Request'}
              </button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
