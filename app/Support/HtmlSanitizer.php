<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Allowlist HTML sanitizer for CMS rich text. Anything not explicitly allowed
 * (scripts, styles, iframes, forms, event handlers, javascript: URLs, ...) is
 * removed; disallowed wrappers are unwrapped so their text content survives.
 */
class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'blockquote',
        'h2', 'h3', 'h4', 'a', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'span',
    ];

    /** Elements dropped together with all of their contents. */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea',
        'select', 'link', 'meta', 'svg', 'math', 'noscript', 'template', 'base', 'frame', 'frameset',
    ];

    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title', 'target', 'rel'],
        'th' => ['colspan', 'rowspan'],
        'td' => ['colspan', 'rowspan'],
    ];

    private const ALLOWED_URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html === null ? null : '';
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="sanitize-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('sanitize-root');
        if (! $root) {
            return trim(strip_tags($html));
        }

        self::sanitizeChildren($root);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output);
    }

    private static function sanitizeChildren(DOMNode $parent): void
    {
        // Snapshot first: the live list changes while nodes are removed or unwrapped.
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if (! $node instanceof DOMElement) {
                if ($node->nodeType !== XML_TEXT_NODE) {
                    $parent->removeChild($node); // comments, processing instructions, CDATA
                }

                continue;
            }

            $tag = strtolower($node->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $parent->removeChild($node);

                continue;
            }

            self::sanitizeChildren($node);

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                while ($node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);

                continue;
            }

            self::sanitizeAttributes($node, $tag);
        }
    }

    private static function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];

        foreach (iterator_to_array($element->attributes) as $attribute) {
            if (! in_array(strtolower($attribute->name), $allowed, true)) {
                $element->removeAttribute($attribute->name);
            }
        }

        if ($tag !== 'a') {
            return;
        }

        $href = $element->getAttribute('href');
        if ($href !== '' && ! self::isSafeUrl($href)) {
            $element->removeAttribute('href');
        }

        if ($element->getAttribute('target') !== '_blank') {
            $element->removeAttribute('target');
        } else {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    public static function isSafeUrl(string $url): bool
    {
        // Browsers ignore control characters and whitespace inside the scheme.
        $normalised = preg_replace('/[\x00-\x20\x7F]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5)) ?? '';

        if (str_starts_with($normalised, '#') || str_starts_with($normalised, '/') && ! str_starts_with($normalised, '//')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($normalised, PHP_URL_SCHEME));

        return in_array($scheme, self::ALLOWED_URL_SCHEMES, true);
    }
}
