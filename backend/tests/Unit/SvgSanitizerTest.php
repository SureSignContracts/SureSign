<?php

namespace Tests\Unit;

use App\Services\SvgSanitizer;
use Tests\TestCase;

class SvgSanitizerTest extends TestCase
{
    // ── Legitimate uploads still work ──────────────────────────────────────

    public function test_valid_company_logo_svg_is_accepted(): void
    {
        $svg = <<<'SVG'
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 40">
    <g fill="#0f0f0f">
        <rect x="0" y="0" width="100" height="40" fill="url(#grad)" />
        <text x="10" y="25" font-family="Arial" font-size="14" style="fill:#333333;font-weight:bold">SureSign</text>
    </g>
    <defs>
        <linearGradient id="grad">
            <stop offset="0%" stop-color="#111111" />
            <stop offset="100%" stop-color="#333333" />
        </linearGradient>
    </defs>
</svg>
SVG;

        $result = SvgSanitizer::sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringContainsString('<svg', $result);
        $this->assertStringContainsString('SureSign', $result);
        // fill="url(#grad)" is a plain presentation attribute, not a style
        // declaration — unaffected by style-attribute handling.
        $this->assertStringContainsString('url(#grad)', $result);
        // The style attribute's declarations (fill, font-weight) are both
        // allow-listed properties with safe values — preserved.
        $this->assertStringContainsString('font-weight', $result);
    }

    public function test_valid_favicon_svg_is_accepted(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><circle cx="8" cy="8" r="7" fill="#111" /></svg>';

        $result = SvgSanitizer::sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringContainsString('<circle', $result);
    }

    public function test_svg_with_comments_is_accepted_and_harmless(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><!-- exported from Figma --><rect width="10" height="10" /></svg>';

        $result = SvgSanitizer::sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringContainsString('<rect', $result);
    }

    // ── Malicious payloads: script execution vectors ───────────────────────

    public function test_svg_containing_script_tag_is_stripped(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script><rect width="10" height="10" /></svg>';

        $result = SvgSanitizer::sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringContainsString('<rect', $result);
    }

    public function test_svg_containing_javascript_uri_is_stripped(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="javascript:alert(1)"><rect width="10" height="10" /></a></svg>';

        $result = SvgSanitizer::sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function test_svg_containing_onload_handler_is_stripped(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="10" height="10" onclick="alert(2)" /></svg>';

        $result = SvgSanitizer::sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('onload', $result);
        $this->assertStringNotContainsString('onclick', $result);
    }

    public function test_svg_containing_foreignobject_is_stripped(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject width="100" height="100"><div xmlns="http://www.w3.org/1999/xhtml">hi</div></foreignObject><rect width="10" height="10" /></svg>';

        $result = SvgSanitizer::sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('foreignObject', $result);
        $this->assertStringContainsString('<rect', $result);
    }

    // ── Malicious payloads: CSS / style vectors ─────────────────────────────

    public function test_svg_with_inline_style_url_is_stripped_but_other_declarations_survive(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10" style="fill:url(http://evil.example/track.png);opacity:0.5" /></svg>';

        $result = SvgSanitizer::sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('url(', $result);
        $this->assertStringNotContainsString('evil.example', $result);
        // opacity is an allow-listed, safe declaration — preserved.
        $this->assertStringContainsString('opacity', $result);
    }

    public function test_svg_with_style_element_is_stripped_entirely(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>@import url(http://evil.example/x.css); rect { fill: red; }</style><rect width="10" height="10" /></svg>';

        $result = SvgSanitizer::sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('<style', $result);
        $this->assertStringNotContainsString('@import', $result);
        $this->assertStringNotContainsString('evil.example', $result);
    }

    public function test_svg_with_disallowed_style_property_is_dropped(): void
    {
        // `behavior` is not on the allow-list at all (legacy IE CSS behavior
        // property could load an external .htc script) — the whole
        // declaration must be dropped even without an explicit url().
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10" style="behavior:url(x.htc);opacity:1" /></svg>';

        $result = SvgSanitizer::sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('behavior', $result);
    }

    // ── Malicious payloads: nested/embedded SVG ─────────────────────────────

    public function test_svg_with_data_svg_xml_base64_href_is_stripped(): void
    {
        $nested = base64_encode('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            . '<image xlink:href="data:image/svg+xml;base64,' . $nested . '" width="10" height="10" />'
            . '</svg>';

        $result = SvgSanitizer::sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('data:image/svg+xml', $result);
        $this->assertStringNotContainsString('base64', $result);
    }

    public function test_svg_with_nested_raw_svg_element_is_accepted_since_nested_svg_is_valid_markup(): void
    {
        // A literal nested <svg> *element* (not a data-URI) is valid SVG
        // markup (used for viewport/coordinate-system nesting) and carries
        // no more risk than any other element once script/event-handler/
        // external-ref stripping has already run on it like any other node.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><svg x="0" y="0" width="5" height="5"><rect width="5" height="5" /></svg></svg>';

        $result = SvgSanitizer::sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringContainsString('<rect', $result);
    }

    public function test_svg_with_data_png_base64_href_is_allowed_as_legitimate_raster_fallback(): void
    {
        $png = base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            . '<image xlink:href="data:image/png;base64,' . $png . '" width="1" height="1" />'
            . '</svg>';

        $result = SvgSanitizer::sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringContainsString('data:image/png', $result);
    }

    // ── Malicious / malformed input: rejected outright (null) ──────────────

    public function test_malformed_xml_is_rejected(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"></svg>'; // unclosed <rect>

        $this->assertNull(SvgSanitizer::sanitize($svg));
    }

    public function test_svg_with_doctype_is_rejected(): void
    {
        $svg = '<?xml version="1.0"?><!DOCTYPE svg><svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10" /></svg>';

        $this->assertNull(SvgSanitizer::sanitize($svg));
    }

    public function test_svg_with_external_entity_xxe_is_rejected(): void
    {
        $svg = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            . '<svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>';

        $this->assertNull(SvgSanitizer::sanitize($svg));
    }

    public function test_svg_with_processing_instruction_is_stripped_not_rejected(): void
    {
        $svg = '<?xml version="1.0"?><?xml-stylesheet type="text/xsl" href="http://evil.example/x.xsl"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10" /></svg>';

        $result = SvgSanitizer::sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('xml-stylesheet', $result);
        $this->assertStringNotContainsString('evil.example', $result);
        $this->assertStringContainsString('<rect', $result);
    }

    public function test_non_svg_root_element_is_rejected(): void
    {
        $notSvg = '<?xml version="1.0"?><root><child /></root>';

        $this->assertNull(SvgSanitizer::sanitize($notSvg));
    }

    public function test_empty_string_is_rejected(): void
    {
        $this->assertNull(SvgSanitizer::sanitize(''));
    }

    public function test_null_byte_is_rejected(): void
    {
        $this->assertNull(SvgSanitizer::sanitize("<svg>\0</svg>"));
    }
}
