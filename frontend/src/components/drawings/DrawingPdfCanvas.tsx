'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import * as pdfjsLib from 'pdfjs-dist';
import type { PDFDocumentProxy, RenderTask } from 'pdfjs-dist';
import { ChevronLeft, ChevronRight, ZoomIn, ZoomOut, Maximize2, AlertCircle } from 'lucide-react';
import api from '@/lib/api';

// Local, committed static worker asset (frontend/public/pdfjs/pdf.worker.min.mjs),
// copied once from node_modules/pdfjs-dist/build/ — mirrors the existing
// Leaflet marker-icon precedent (frontend/public/leaflet/), not a build
// script step. Deliberately NOT a CDN URL (unpinned/fragile) and NOT the
// dev-only "fake worker" fallback (Part B) — this is a real Worker script
// served by Next's own static file handling in both dev and the production
// standalone build (same COPY ... ./public step already used for Leaflet).
//
// SECURITY NOTE (pdfjs-dist 5.6.205 — the newest version whose engines
// requirement, Node >=20.19.0, this repo's actual `node:20-alpine`
// production build satisfies; 5.7.x/6.x require Node >=22.13, which this
// repo does not run): GHSA-hq66-cqwq-w95j (arbitrary JS execution from a
// malicious PDF, affecting >=5.6.83 <6.2.108) is reachable ONLY through
// `enableScripting`, an option that exists exclusively on the optional
// high-level `pdf_viewer.js`/AnnotationLayerBuilder components — never on
// the low-level `getDocument()` + canvas-render API used here. This viewer
// never imports `pdf_viewer.js`, never builds an AnnotationLayer, and never
// enables PDF scripting — the vulnerable code path is structurally absent
// from this integration, not merely disabled by a flag. `isEvalSupported:
// false` is set below as additional defence-in-depth.
pdfjsLib.GlobalWorkerOptions.workerSrc = '/pdfjs/pdf.worker.min.mjs';

const MIN_SCALE = 0.5;
const MAX_SCALE = 3;
const ZOOM_STEP = 0.25;
const PAGE_PADDING = 32; // px — matches the viewer shell's own padding, kept in sync manually since fit-width must subtract exactly what the shell actually applies.

type LoadState = 'loading' | 'ready' | 'error';

export type PageGeometry = { width: number; height: number; pageNumber: number };

export default function DrawingPdfCanvas({ previewEndpoint, initialPage, onPageGeometryChange, children }: {
  previewEndpoint: string;
  /**
   * Drawing Phase 6B Part Z — the page to open on, e.g. from a linked
   * record's "Open Drawing" action (`?page=`). Read only once, at document
   * load time (like `onPageGeometryChange` below, deliberately excluded
   * from the load effect's deps — a parent re-render must never reload the
   * PDF binary or jump the page back). Clamped to the real page count once
   * known; a missing/invalid value defaults to page 1 exactly as before.
   */
  initialPage?: number;
  /**
   * Reports the CSS-rendered page's display dimensions (Drawing Phase 5
   * Part B) — always `viewport.width`/`viewport.height` from pdf.js, i.e.
   * exactly `canvas.style.width`/`canvas.style.height` below, NEVER
   * `canvas.width`/`canvas.height` (the devicePixelRatio-scaled backing
   * store). This is the only geometry a hotspot overlay may use to convert
   * normalized (0-1) coordinates into on-screen placement. Called with
   * `null` whenever no page is currently rendered (loading/error/unmount)
   * so a consumer never positions markers against stale dimensions.
   * DrawingPdfCanvas itself has no knowledge of hotspots — this is its
   * entire contract with any overlay a caller chooses to compose via
   * `children`.
   */
  onPageGeometryChange?: (geometry: PageGeometry | null) => void;
  /** Rendered inside the same relative-positioned page wrapper as the
   *  canvas, so an absolutely-positioned overlay naturally aligns to the
   *  page — see the wrapper div below. */
  children?: React.ReactNode;
}) {
  const containerRef = useRef<HTMLDivElement>(null);
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const pdfDocRef = useRef<PDFDocumentProxy | null>(null);
  const renderTaskRef = useRef<RenderTask | null>(null);
  // Bumped on every load/unmount so an in-flight async chain from a
  // superseded load can recognise it's stale and bail out without acting on
  // a ref that now belongs to a different document.
  const generationRef = useRef(0);
  // Separate generation counter for individual page renders (Part N). A
  // page render has an `await pdfDoc.getPage(...)` gap before it ever
  // touches the canvas — two renderPage() calls triggered close together
  // (e.g. a zoom click flipping both `fitWidth` and `scale` in the same
  // handler) can both pass a synchronous `renderTaskRef.current?.cancel()`
  // check before either has stored its own task in that ref, then both
  // proceed to call `page.render()` on the same canvas. Cancelling on
  // entry is necessary but not sufficient — every render call must also
  // re-check, AFTER its await, that it is still the most recently
  // requested one before touching the canvas at all.
  const renderGenerationRef = useRef(0);

  const [state, setState] = useState<LoadState>('loading');
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [numPages, setNumPages] = useState(0);
  const [pageNum, setPageNum] = useState(1);
  const [scale, setScale] = useState(1);
  const [fitWidth, setFitWidth] = useState(true);
  const [containerWidth, setContainerWidth] = useState(0);

  // ── Load the PDF binary once via the existing authenticated api client ──
  // No synchronous setState at the top of this effect: the parent mounts
  // this component with `key={documentId}` (a fresh instance per Document,
  // not a prop update on a reused one), so `state`/`errorMessage`'s own
  // useState initial values ('loading'/null) already cover the reset —
  // every setState below runs only from the async continuation.
  useEffect(() => {
    const generation = ++generationRef.current;

    api.get(previewEndpoint, { responseType: 'arraybuffer' })
      .then(async (res) => {
        if (generationRef.current !== generation) return;

        const contentType = (res.headers['content-type'] as string) || '';
        if (!contentType.includes('pdf')) {
          // Non-PDF content type reaching this component is a caller bug
          // (the viewer page only mounts this for application/pdf) — fail
          // safe rather than hand pdf.js bytes it can't parse.
          setErrorMessage('This document is not a PDF.');
          setState('error');
          return;
        }

        try {
          const loadingTask = pdfjsLib.getDocument({
            data: new Uint8Array(res.data as ArrayBuffer),
            isEvalSupported: false,
          });
          const pdfDoc = await loadingTask.promise;
          if (generationRef.current !== generation) {
            pdfDoc.destroy();
            return;
          }
          pdfDocRef.current = pdfDoc;
          setNumPages(pdfDoc.numPages);
          setPageNum(initialPage && initialPage >= 1 && initialPage <= pdfDoc.numPages ? initialPage : 1);
          setState('ready');
        } catch {
          if (generationRef.current === generation) {
            setErrorMessage('Could not parse this PDF. The file may be corrupted.');
            setState('error');
          }
        }
      })
      .catch((err) => {
        if (generationRef.current !== generation) return;
        const status = err?.response?.status;
        if (status === 404) setErrorMessage('The linked document could not be found.');
        else if (status === 403) setErrorMessage('You do not have access to this document.');
        else setErrorMessage('Could not load the document. Please try again.');
        setState('error');
      });

    return () => {
      // generationRef is a plain mutable counter (never a DOM node) —
      // reading/incrementing whatever its current value happens to be at
      // cleanup time is exactly the intended race-safety behaviour here,
      // not the stale-DOM-ref hazard this lint rule otherwise guards
      // against.
      // eslint-disable-next-line react-hooks/exhaustive-deps
      generationRef.current++;
      renderTaskRef.current?.cancel();
      renderTaskRef.current = null;
      pdfDocRef.current?.destroy();
      pdfDocRef.current = null;
      // Deliberately NOT in this effect's deps — onPageGeometryChange is
      // intentionally excluded so an unmemoized inline callback from the
      // parent can never cause this effect to refire and reload the PDF
      // binary on an unrelated re-render (Part N). The closure captured
      // when this effect instance was created is a perfectly valid
      // "geometry is now gone" signal regardless of whether a newer
      // callback reference exists by the time this runs.
      onPageGeometryChange?.(null);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [previewEndpoint]);

  // ── Container width tracking (ResizeObserver) — drives Fit Width ────────
  useEffect(() => {
    const el = containerRef.current;
    if (!el) return;
    const observer = new ResizeObserver((entries) => {
      const width = entries[0]?.contentRect.width;
      if (width) setContainerWidth(width);
    });
    observer.observe(el);
    setContainerWidth(el.clientWidth);
    return () => observer.disconnect();
  }, []);

  // ── Render the current page whenever page/scale/fit-width/container size
  //    changes — reuses the already-loaded pdfDocRef, never refetches the
  //    binary (Part W). Always derives its target scale from live state
  //    (no separate "override" call path) so every request goes through
  //    the exact same single code path and generation guard — see
  //    renderGenerationRef's own comment for why the guard is needed even
  //    with the synchronous `cancel()` call below. ─────────────────────────
  const renderPage = useCallback(async () => {
    const pdfDoc = pdfDocRef.current;
    const canvas = canvasRef.current;
    if (!pdfDoc || !canvas) return;

    const myRenderGeneration = ++renderGenerationRef.current;
    renderTaskRef.current?.cancel();

    const page = await pdfDoc.getPage(pageNum);
    // A newer render was requested while awaiting getPage() above — bail
    // out before touching the canvas at all, rather than racing whichever
    // call reaches page.render() first.
    if (renderGenerationRef.current !== myRenderGeneration) return;

    const unscaledViewport = page.getViewport({ scale: 1 });

    let effectiveScale = scale;
    if (fitWidth && containerWidth > 0) {
      effectiveScale = Math.min(MAX_SCALE, Math.max(MIN_SCALE, (containerWidth - PAGE_PADDING) / unscaledViewport.width));
    }

    const viewport = page.getViewport({ scale: effectiveScale });

    // devicePixelRatio-aware backing store (Part M) — the canvas' actual
    // pixel buffer is rendered at native resolution, then scaled back down
    // via CSS width/height, so high-DPI screens stay crisp instead of
    // upscaling a low-resolution raster.
    const dpr = window.devicePixelRatio || 1;
    canvas.width = Math.floor(viewport.width * dpr);
    canvas.height = Math.floor(viewport.height * dpr);
    canvas.style.width = `${viewport.width}px`;
    canvas.style.height = `${viewport.height}px`;

    const context = canvas.getContext('2d');
    if (!context) return;
    context.setTransform(dpr, 0, 0, dpr, 0, 0);

    const task = page.render({ canvasContext: context, viewport, canvas });
    renderTaskRef.current = task;
    try {
      await task.promise;
    } catch (err: unknown) {
      // A cancelled render throws by design — not a real error.
      if ((err as { name?: string })?.name !== 'RenderingCancelledException') throw err;
    } finally {
      if (renderTaskRef.current === task) renderTaskRef.current = null;
    }

    // Only commit the fit-width-derived scale if this render is still the
    // current one, and only when it actually changed — an unconditional
    // setScale here would retrigger this same effect every time (scale is
    // now a dependency), even when the recomputed value is identical.
    if (fitWidth && renderGenerationRef.current === myRenderGeneration && Math.abs(effectiveScale - scale) > 0.001) {
      setScale(effectiveScale);
    }

    // Report the CSS-rendered page dimensions (Drawing Phase 5 Part B) —
    // viewport.width/height, i.e. exactly canvas.style.width/height above,
    // never the DPR-scaled canvas.width/height backing store. Guarded by
    // the same render-generation check as everything else here so a
    // superseded render (e.g. a page/zoom change that fired mid-render)
    // never reports geometry for a page that's no longer the one on
    // screen.
    if (renderGenerationRef.current === myRenderGeneration) {
      onPageGeometryChange?.({ width: viewport.width, height: viewport.height, pageNumber: pageNum });
    }
  }, [pageNum, scale, fitWidth, containerWidth, onPageGeometryChange]);

  useEffect(() => {
    if (state !== 'ready') return;
    renderPage().catch(() => {
      setErrorMessage('Could not render this page.');
      setState('error');
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [state, pageNum, scale, fitWidth, containerWidth]);

  // A zoom action only ever updates state — the effect above is the single
  // place renderPage() is ever called, so there is exactly one caller and
  // no possibility of two overlapping calls racing each other regardless
  // of how many state fields a single action changes at once (React
  // batches them into one re-render, so the effect fires once with all of
  // them already reflected).
  const zoomTo = useCallback((next: number) => {
    setFitWidth(false);
    setScale(Math.min(MAX_SCALE, Math.max(MIN_SCALE, next)));
  }, []);

  const goToPage = useCallback((next: number) => {
    setPageNum(Math.min(numPages, Math.max(1, next)));
  }, [numPages]);

  // ── Keyboard navigation (Part AA) — left/right only, and only while the
  //    viewer itself has focus/no form control is active, so this never
  //    interferes with an unrelated input elsewhere on the page. ──────────
  useEffect(() => {
    function onKeyDown(e: KeyboardEvent) {
      const tag = (e.target as HTMLElement)?.tagName;
      if (tag === 'INPUT' || tag === 'TEXTAREA') return;
      if (e.key === 'ArrowLeft') goToPage(pageNum - 1);
      else if (e.key === 'ArrowRight') goToPage(pageNum + 1);
    }
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [pageNum, goToPage]);

  if (state === 'error') {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-20 text-center">
        <AlertCircle size={28} style={{ color: '#f87171' }} />
        <p className="text-sm" style={{ color: 'var(--text-primary)' }}>{errorMessage}</p>
      </div>
    );
  }

  return (
    <div className="flex flex-col h-full">
      {/* Toolbar */}
      <div
        className="flex items-center justify-center gap-1 sm:gap-2 flex-wrap px-3 py-2 flex-shrink-0"
        style={{ borderBottom: '1px solid var(--border)', backgroundColor: 'var(--bg-elevated)' }}
      >
        <button
          onClick={() => goToPage(pageNum - 1)}
          disabled={state !== 'ready' || pageNum <= 1}
          aria-label="Previous page"
          className="p-1.5 rounded-lg disabled:opacity-30 transition-colors hover:bg-[var(--bg-hover)]"
        >
          <ChevronLeft size={16} style={{ color: 'var(--text-secondary)' }} />
        </button>
        <span className="text-xs tabular-nums px-1" style={{ color: 'var(--text-secondary)' }} aria-live="polite">
          {state === 'ready' ? `Page ${pageNum} of ${numPages}` : 'Loading…'}
        </span>
        <button
          onClick={() => goToPage(pageNum + 1)}
          disabled={state !== 'ready' || pageNum >= numPages}
          aria-label="Next page"
          className="p-1.5 rounded-lg disabled:opacity-30 transition-colors hover:bg-[var(--bg-hover)]"
        >
          <ChevronRight size={16} style={{ color: 'var(--text-secondary)' }} />
        </button>

        <span className="w-px h-5 mx-1" style={{ backgroundColor: 'var(--border)' }} />

        <button
          onClick={() => zoomTo(scale - ZOOM_STEP)}
          disabled={state !== 'ready' || (!fitWidth && scale <= MIN_SCALE)}
          aria-label="Zoom out"
          className="p-1.5 rounded-lg disabled:opacity-30 transition-colors hover:bg-[var(--bg-hover)]"
        >
          <ZoomOut size={16} style={{ color: 'var(--text-secondary)' }} />
        </button>
        <span data-testid="drawing-zoom-level" className="text-xs tabular-nums w-12 text-center" style={{ color: 'var(--text-secondary)' }}>
          {Math.round(scale * 100)}%
        </span>
        <button
          onClick={() => zoomTo(scale + ZOOM_STEP)}
          disabled={state !== 'ready' || scale >= MAX_SCALE}
          aria-label="Zoom in"
          className="p-1.5 rounded-lg disabled:opacity-30 transition-colors hover:bg-[var(--bg-hover)]"
        >
          <ZoomIn size={16} style={{ color: 'var(--text-secondary)' }} />
        </button>

        <span className="w-px h-5 mx-1" style={{ backgroundColor: 'var(--border)' }} />

        <button
          onClick={() => setFitWidth(true)}
          disabled={state !== 'ready'}
          className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium disabled:opacity-30 transition-colors hover:bg-[var(--bg-hover)]"
          style={fitWidth
            ? { backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }
            : { color: 'var(--text-secondary)' }}
        >
          <Maximize2 size={13} /> Fit Width
        </button>
      </div>

      {/* Page viewport — `children` (Drawing Phase 5's DrawingHotspotOverlay,
          composed by the caller) renders as an absolutely-positioned
          sibling inside this same relative wrapper, sharing the canvas'
          exact rendered CSS dimensions. This component has no knowledge of
          what `children` actually is (Part Z/AA) — geometry is reported
          separately via onPageGeometryChange. */}
      <div
        ref={containerRef}
        className="flex-1 min-h-0 overflow-auto flex items-start justify-center p-4"
        style={{ backgroundColor: 'var(--bg-elevated)' }}
      >
        <div className="relative inline-block">
          {state === 'loading' && (
            <div className="flex items-center justify-center py-24 px-32">
              <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Loading document…</p>
            </div>
          )}
          <canvas
            ref={canvasRef}
            role="img"
            aria-label={state === 'ready' ? `Drawing page ${pageNum} of ${numPages}` : 'Loading drawing page'}
            className={state === 'ready' ? 'shadow-lg' : 'hidden'}
            style={{ backgroundColor: '#fff' }}
          />
          {state === 'ready' && children}
        </div>
      </div>
    </div>
  );
}
