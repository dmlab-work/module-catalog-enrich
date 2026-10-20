<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\State;

/**
 * Turns product HTML into plain single-line text.
 */
class TextCleaner
{
    /**
     * Strip markup, decode entities and collapse whitespace.
     *
     * @param string $text
     * @return string
     */
    public function clean(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = (string)preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#isu', ' ', $text);
        // A space before each tag keeps words of adjacent blocks apart ("<p>a</p><p>b</p>" → "a b").
        $text = strip_tags(str_replace('<', ' <', $text));
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- plain text for the model, never rendered as HTML
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string)preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }

    /**
     * First $max characters (multibyte-safe).
     *
     * @param string $text
     * @param int $max
     * @return string
     */
    public function cut(string $text, int $max): string
    {
        return mb_substr($text, 0, max(0, $max), 'UTF-8');
    }
}
