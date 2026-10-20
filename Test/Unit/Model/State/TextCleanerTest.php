<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\State;

use DmLab\CatalogEnrich\Model\State\TextCleaner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TextCleanerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cleanProvider(): array
    {
        return [
            'plain' => ['Red cotton shirt', 'Red cotton shirt'],
            'tags stripped' => ['<p>Red <b>cotton</b> shirt</p>', 'Red cotton shirt'],
            'adjacent blocks keep a space' => ['<p>Red</p><p>cotton</p><br/>shirt', 'Red cotton shirt'],
            'script removed' => ['Red<script type="text/javascript">alert("x")</script> shirt', 'Red shirt'],
            'style removed' => ["<style>\n.a { color: red; }\n</style>Cotton", 'Cotton'],
            'uppercase style removed' => ['<STYLE media="all">p{}</STYLE>Cotton', 'Cotton'],
            'entities decoded' => ['Tom &amp; Jerry&#39;s &quot;tee&quot; &euro;5', 'Tom & Jerry\'s "tee" €5'],
            'nbsp collapsed' => ['Red&nbsp;&nbsp;shirt', 'Red shirt'],
            'whitespace collapsed and trimmed' => ["  Red \n\t cotton\r\n  shirt  ", 'Red cotton shirt'],
            'less-than in text kept' => ['size a < b', 'size a < b'],
            'empty' => ['', ''],
            'markup only' => ['<p> </p><style>x</style>', ''],
            'multibyte' => ['<p>Футболка  из хлопка</p>', 'Футболка из хлопка'],
            'invalid utf-8 scrubbed' => ["Red \xC3 shirt", 'Red ? shirt'],
        ];
    }

    #[DataProvider('cleanProvider')]
    public function testClean(string $input, string $expected): void
    {
        self::assertSame($expected, (new TextCleaner())->clean($input));
    }

    public function testCutIsMultibyteSafe(): void
    {
        $cleaner = new TextCleaner();
        $text = str_repeat('ж', 6005);

        $cut = $cleaner->cut($text, 6000);

        self::assertSame(6000, mb_strlen($cut, 'UTF-8'));
        self::assertTrue(mb_check_encoding($cut, 'UTF-8'));
        self::assertSame('abc', $cleaner->cut('abc', 6000));
        self::assertSame('', $cleaner->cut('abc', -1));
    }
}
