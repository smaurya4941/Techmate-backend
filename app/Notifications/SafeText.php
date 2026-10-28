<?php

namespace App\Notifications;

/**
 * Neutralizes visitor-supplied text before it enters a Markdown mail body:
 * strips tags and control characters and escapes Markdown syntax so submitted
 * content cannot inject links, images or HTML into staff or customer email.
 */
final class SafeText
{
    public static function inline(?string $value, int $limit = 300): string
    {
        $text = strip_tags((string) $value);
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $text = mb_substr(trim($text), 0, $limit);

        return preg_replace('/([\\\\`*_{}\[\]()#+!|<>~&])/', '\\\\$1', $text) ?? '';
    }

    public static function block(?string $value, int $limit = 1500): string
    {
        $text = strip_tags((string) $value);
        $text = preg_replace('/[^\P{C}\n]+/u', ' ', $text) ?? '';
        $text = mb_substr(trim($text), 0, $limit);

        return preg_replace('/([\\\\`*_{}\[\]()#+!|<>~&])/', '\\\\$1', $text) ?? '';
    }
}
