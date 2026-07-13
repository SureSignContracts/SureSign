<?php

namespace App\Services;

/**
 * Minimal SVG sanitiser for the small number of branding/favicon upload
 * paths that accept SVG. SVG is XML — an unsanitised upload can carry
 * <script>, event-handler attributes, <foreignObject>, external entity
 * references (XXE) or remote <image>/<use> references, any of which can
 * execute in a victim's browser when the file is opened directly (not just
 * when embedded via <img>, which does not execute scripts).
 *
 * This is a pragmatic denylist-based sanitiser, not a full spec-compliant
 * one — see the audit report for residual risk notes. It is deliberately
 * conservative: anything it cannot confidently parse is rejected rather
 * than passed through.
 */
class SvgSanitizer
{
    private const DANGEROUS_TAGS = [
        'script', 'foreignobject', 'iframe', 'embed', 'object', 'link',
        'meta', 'style',
    ];

    /**
     * `style` attribute properties known to be purely presentational — no
     * property here can load a resource, run script, or navigate. Anything
     * not on this list is dropped from a style attribute rather than passed
     * through; this is deliberately narrow (see isUnsafeStyleValue() for the
     * value-level checks applied on top of it).
     */
    private const ALLOWED_STYLE_PROPERTIES = [
        'fill', 'fill-opacity', 'fill-rule',
        'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap',
        'stroke-linejoin', 'stroke-dasharray', 'stroke-dashoffset',
        'opacity', 'color', 'stop-color', 'stop-opacity',
        'font-family', 'font-size', 'font-weight', 'font-style',
        'text-anchor', 'text-decoration', 'letter-spacing',
        'visibility', 'display',
    ];

    /**
     * Sanitise raw SVG markup. Returns the cleaned markup, or null if the
     * input could not be safely parsed / was not valid SVG at all.
     */
    public static function sanitize(string $rawSvg): ?string
    {
        if (trim($rawSvg) === '' || str_contains($rawSvg, "\0")) {
            return null;
        }

        // Reject a DOCTYPE outright rather than trying to selectively allow
        // it — DOCTYPEs are how external/parameter entity XXE payloads are
        // smuggled in, and a favicon/logo has no legitimate need for one.
        if (preg_match('/<!DOCTYPE/i', $rawSvg)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        // Explicitly avoid LIBXML_NOENT (would expand entities) and never
        // load external entities/DTDs — belt-and-braces against XXE even
        // though the DOCTYPE check above already blocks the common vector.
        $doc = new \DOMDocument();
        $ok = @$doc->loadXML($rawSvg, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$ok || $doc->documentElement === null || strtolower($doc->documentElement->localName) !== 'svg') {
            return null;
        }

        self::stripDangerousNodes($doc);
        self::sanitizeStyleAttributes($doc);
        self::stripProcessingInstructions($doc);

        $cleaned = $doc->saveXML();

        return $cleaned !== false ? $cleaned : null;
    }

    private static function stripDangerousNodes(\DOMDocument $doc): void
    {
        $xpath = new \DOMXPath($doc);

        foreach (self::DANGEROUS_TAGS as $tag) {
            foreach (iterator_to_array($xpath->query("//*[translate(local-name(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='{$tag}']")) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // Strip every `on*` event-handler attribute and any attribute whose
        // value starts with `javascript:` (href/xlink:href XSS vector).
        foreach (iterator_to_array($xpath->query('//@*')) as $attr) {
            /** @var \DOMAttr $attr */
            $name = strtolower($attr->nodeName);
            $value = trim($attr->nodeValue ?? '');

            $isEventHandler = str_starts_with($name, 'on');
            $isScriptUri = (bool) preg_match('/^\s*javascript:/i', $value);
            $isExternalRef = in_array($name, ['href', 'xlink:href'], true)
                && $value !== ''
                && !str_starts_with($value, '#')
                && !self::isAllowedDataUri($value);

            if ($isEventHandler || $isScriptUri || $isExternalRef) {
                $attr->ownerElement?->removeAttributeNode($attr);
            }
        }
    }

    /**
     * Raster data URIs (`data:image/png;base64,...` etc.) are inert — a
     * browser cannot execute anything inside a decoded bitmap — and are a
     * common, legitimate way to embed a rasterised fallback logo inside an
     * SVG wrapper, so they're allowed through on href/xlink:href.
     *
     * `data:image/svg+xml` is different: it's a *nested SVG document*, which
     * is itself active content (it can carry its own <script>, event
     * handlers, etc.) that this single sanitisation pass does not descend
     * into. No legitimate branding/favicon workflow needs a nested SVG data
     * URI, so rather than adding recursive-decode-and-resanitise complexity
     * (its own source of bugs — nesting depth, decode failures, re-encoding
     * correctness), it's rejected outright here.
     */
    private static function isAllowedDataUri(string $value): bool
    {
        $lower = strtolower(ltrim($value));

        if (str_starts_with($lower, 'data:image/svg+xml')) {
            return false;
        }

        return str_starts_with($lower, 'data:image/');
    }

    /**
     * Reduce every `style` attribute to a narrow allow-list of presentation
     * properties (ALLOWED_STYLE_PROPERTIES) with safe values, instead of
     * stripping the attribute wholesale — legitimate exported logos commonly
     * carry inline `style="fill:...;stroke:..."` from design tools. Unknown
     * properties, and any value that could load a resource or run script,
     * are dropped individually; only if nothing safe remains is the whole
     * attribute removed.
     */
    private static function sanitizeStyleAttributes(\DOMDocument $doc): void
    {
        $xpath = new \DOMXPath($doc);

        foreach (iterator_to_array($xpath->query('//@style')) as $attr) {
            /** @var \DOMAttr $attr */
            $safeDeclarations = [];

            foreach (explode(';', $attr->nodeValue ?? '') as $declaration) {
                $parts = explode(':', $declaration, 2);
                if (count($parts) !== 2) {
                    continue;
                }

                $property = strtolower(trim($parts[0]));
                $value = trim($parts[1]);

                if ($value === '' || !in_array($property, self::ALLOWED_STYLE_PROPERTIES, true)) {
                    continue;
                }

                if (self::isUnsafeStyleValue($value)) {
                    continue;
                }

                $safeDeclarations[] = "{$property}: {$value}";
            }

            $owner = $attr->ownerElement;
            if ($safeDeclarations === []) {
                $owner?->removeAttributeNode($attr);
            } else {
                $owner?->setAttribute('style', implode('; ', $safeDeclarations));
            }
        }
    }

    /**
     * `url(...)` is rejected unconditionally (including a local `url(#id)`
     * gradient/pattern fragment reference) rather than trying to distinguish
     * safe-local from unsafe-external — that distinction is easy to get
     * wrong, and gradient/pattern fills applied via the plain `fill`/
     * `stroke` presentation attributes (the more common real-world export
     * pattern) are entirely unaffected by style-attribute handling. Also
     * rejects `@import`, CSS-comment obfuscation tricks (`java/*comment*
     * /script:`), and any explicit script/navigation scheme.
     */
    private static function isUnsafeStyleValue(string $value): bool
    {
        $lower = strtolower($value);

        foreach (['url(', '@import', 'expression(', 'javascript:', 'behavior:', '/*'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function stripProcessingInstructions(\DOMDocument $doc): void
    {
        $xpath = new \DOMXPath($doc);

        // Processing instructions other than the XML declaration itself
        // (which libxml handles separately, not as a PI node) have no
        // legitimate use in a favicon/logo — and an xml-stylesheet PI
        // specifically can reference an external XSLT resource, which is an
        // SSRF-adjacent vector if the file is ever opened directly.
        foreach (iterator_to_array($xpath->query('//processing-instruction()')) as $pi) {
            $pi->parentNode?->removeChild($pi);
        }
    }
}
