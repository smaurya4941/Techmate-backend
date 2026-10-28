<?php

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> payload => substring that must NOT survive */
    public static function attackPayloads(): array
    {
        return [
            'script tag' => ['<p>a</p><script>alert(1)</script>', 'script'],
            'inline handler' => ['<p onmouseover="alert(1)">a</p>', 'onmouseover'],
            'javascript href' => ['<a href="javascript:alert(1)">x</a>', 'javascript'],
            'entity-obfuscated javascript href' => ['<a href="&#106;avascript:alert(1)">x</a>', 'avascript'],
            'whitespace-obfuscated scheme' => ["<a href=\"java\tscript:alert(1)\">x</a>", 'script:'],
            'data uri' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>', 'data:'],
            'iframe' => ['<iframe src="https://evil.test"></iframe>', 'iframe'],
            'object/embed' => ['<object data="x"></object><embed src="x">', 'embed'],
            'svg onload' => ['<svg onload="alert(1)"><circle/></svg>', 'svg'],
            'style tag' => ['<style>body{display:none}</style>', 'style'],
            'style attribute' => ['<p style="background:url(javascript:alert(1))">x</p>', 'style='],
            'img onerror' => ['<img src=x onerror=alert(1)>', 'onerror'],
            'form' => ['<form action="https://evil.test"><input name="p"></form>', 'form'],
            'comment payload' => ['<!--<script>alert(1)</script>-->ok', 'script'],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.test">', 'meta'],
            'base' => ['<base href="https://evil.test/">', 'base'],
        ];
    }

    #[DataProvider('attackPayloads')]
    public function test_attack_payloads_are_neutralised(string $payload, string $forbidden): void
    {
        $this->assertStringNotContainsStringIgnoringCase($forbidden, HtmlSanitizer::clean($payload));
    }

    public function test_safe_formatting_survives(): void
    {
        $html = '<h2>Title</h2><p>Some <strong>bold</strong> and <em>italic</em> text with a <a href="https://example.test/page" title="t">link</a>.</p>'
            .'<ul><li>One</li><li>Two</li></ul><table><tbody><tr><td colspan="2">Cell</td></tr></tbody></table>';

        $this->assertSame($html, HtmlSanitizer::clean($html));
    }

    public function test_relative_mailto_and_tel_links_are_allowed(): void
    {
        $out = HtmlSanitizer::clean('<a href="/contact">c</a><a href="mailto:a@b.test">m</a><a href="tel:+911234567890">t</a><a href="#top">h</a>');

        foreach (['href="/contact"', 'href="mailto:a@b.test"', 'href="tel:+911234567890"', 'href="#top"'] as $href) {
            $this->assertStringContainsString($href, $out);
        }
    }

    public function test_protocol_relative_links_are_dropped(): void
    {
        $this->assertStringNotContainsString('href', HtmlSanitizer::clean('<a href="//evil.test/x">x</a>'));
    }

    public function test_external_target_blank_links_get_noopener(): void
    {
        $out = HtmlSanitizer::clean('<a href="https://a.test" target="_blank" rel="opener">x</a>');

        $this->assertStringContainsString('rel="noopener noreferrer"', $out);
        $this->assertStringNotContainsString('rel="opener"', $out);
    }

    public function test_unknown_wrappers_are_unwrapped_but_text_is_kept(): void
    {
        $this->assertSame('<p>keep me</p>', HtmlSanitizer::clean('<div><font color="red"><p>keep me</p></font></div>'));
    }

    public function test_null_and_empty_inputs(): void
    {
        $this->assertNull(HtmlSanitizer::clean(null));
        $this->assertSame('', HtmlSanitizer::clean('   '));
    }

    public function test_cleaning_is_idempotent(): void
    {
        $once = HtmlSanitizer::clean('<p>Hi <a href="https://x.test" target="_blank">x</a><script>1</script></p>');

        $this->assertSame($once, HtmlSanitizer::clean($once));
    }
}
