#!/usr/bin/env node
/**
 * Converts a DOCX to styled HTML.
 * Uses mammoth for content, then applies paragraph/cell background colours
 * and run font colours extracted from the raw DOCX XML.
 */

const path    = require('path');
const fs      = require('fs');
const mammoth = require(path.join('/home/julz/.local/lib/node_modules/mammoth'));

const MAMMOTH_MODULES = '/home/julz/.local/lib/node_modules/mammoth/node_modules';

const docxPath   = process.argv[2];
const outputPath = process.argv[3];
if (!docxPath || !outputPath) {
  process.stderr.write('Usage: docx_to_html.cjs <input.docx> <output.html>\n');
  process.exit(1);
}

// ── XML helpers ──────────────────────────────────────────────────────────────

function attr(el, localName) {
  return el.getAttribute('w:' + localName) || el.getAttribute(localName) || null;
}

function children(el, localName) {
  const result = [];
  for (let i = 0; i < el.childNodes.length; i++) {
    const child = el.childNodes[i];
    if (child.localName === localName) result.push(child);
  }
  return result;
}

function firstChild(el, localName) {
  return children(el, localName)[0] || null;
}

function normColor(hex) {
  if (!hex || hex === 'auto' || hex === 'none') return null;
  hex = hex.replace('#', '').trim();
  return hex.length === 6 ? '#' + hex.toUpperCase() : null;
}

// ── Extract styles in document order from word/document.xml ─────────────────

function extractStyles(xml) {
  const { DOMParser } = require(path.join(MAMMOTH_MODULES, '@xmldom/xmldom'));
  const doc  = new DOMParser().parseFromString(xml, 'text/xml');
  const body = doc.getElementsByTagNameNS('*', 'body')[0];
  if (!body) return { paragraphStyles: [], cellStyles: [] };

  const paragraphStyles = [];
  const cellStyles      = [];

  function walk(node) {
    for (let i = 0; i < node.childNodes.length; i++) {
      const child = node.childNodes[i];
      if (!child.localName) continue;

      if (child.localName === 'p') {
        const pPr = firstChild(child, 'pPr');
        let bgColor = null, color = null;

        if (pPr) {
          const shd = firstChild(pPr, 'shd');
          if (shd) bgColor = normColor(attr(shd, 'fill'));
        }

        // Dominant run colour (first run with an explicit color)
        for (const r of children(child, 'r')) {
          const rPr = firstChild(r, 'rPr');
          if (rPr) {
            const colorEl = firstChild(rPr, 'color');
            if (colorEl) {
              const c = normColor(attr(colorEl, 'val'));
              if (c) { color = c; break; }
            }
          }
        }

        paragraphStyles.push({ bgColor, color });

      } else if (child.localName === 'tc') {
        const tcPr = firstChild(child, 'tcPr');
        let bgColor = null;
        if (tcPr) {
          const shd = firstChild(tcPr, 'shd');
          if (shd) bgColor = normColor(attr(shd, 'fill'));
        }
        cellStyles.push({ bgColor });
        walk(child);

      } else {
        walk(child);
      }
    }
  }

  walk(body);
  return { paragraphStyles, cellStyles };
}

// ── Post-process HTML: apply inline styles ───────────────────────────────────

function applyStyles(html, { paragraphStyles, cellStyles }) {
  let pIdx = 0, tdIdx = 0;

  html = html.replace(/<p(\s[^>]*)?>/g, (match, attrs) => {
    const style = paragraphStyles[pIdx++];
    if (!style) return match;
    const parts = [];
    if (style.bgColor) parts.push(`background-color:${style.bgColor};padding:4px 8px`);
    if (style.color)   parts.push(`color:${style.color}`);
    if (!parts.length) return match;
    // Merge with existing style attr if present
    const existingStyle = attrs && attrs.match(/style="([^"]*)"/);
    const merged = existingStyle
      ? attrs.replace(/style="[^"]*"/, `style="${existingStyle[1]};${parts.join(';')}"`)
      : (attrs || '') + ` style="${parts.join(';')}"`;
    return `<p${merged}>`;
  });

  html = html.replace(/<td(\s[^>]*)?>/g, (match, attrs) => {
    const style = cellStyles[tdIdx++];
    if (!style || !style.bgColor) return match;
    const existingStyle = attrs && attrs.match(/style="([^"]*)"/);
    const merged = existingStyle
      ? attrs.replace(/style="[^"]*"/, `style="${existingStyle[1]};background-color:${style.bgColor};padding:6px 8px"`)
      : (attrs || '') + ` style="background-color:${style.bgColor};padding:6px 8px"`;
    return `<td${merged}>`;
  });

  return html;
}

// ── Convert images to inline base64 ─────────────────────────────────────────
const imageConverter = mammoth.images.imgElement(image =>
  image.read('base64').then(data => ({
    src: `data:${image.contentType};base64,${data}`,
  }))
);

// ── Run ───────────────────────────────────────────────────────────────────────
const docxBuffer = fs.readFileSync(docxPath);
const JSZip = require(path.join(MAMMOTH_MODULES, 'jszip'));

JSZip.loadAsync(docxBuffer)
  .then(zip => zip.files['word/document.xml'].async('string'))
  .then(xml => {
    const styles = extractStyles(xml);

    return mammoth
      .convertToHtml({ buffer: docxBuffer }, { convertImage: imageConverter })
      .then(result => {
        const body = applyStyles(result.value, styles);
        fs.writeFileSync(outputPath, body, 'utf8');
        process.exit(0);
      });
  })
  .catch(err => {
    process.stderr.write(String(err) + '\n');
    process.exit(1);
  });
