<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Dedup;

use DmLab\CatalogEnrich\Model\Dedup\CanonicalJson;
use PHPUnit\Framework\TestCase;

class CanonicalJsonTest extends TestCase
{
    public function testSortsAssociativeKeysRecursivelyAndKeepsListOrder(): void
    {
        $json = (new CanonicalJson())->encode(['b' => ['z' => 1, 'a' => [3, 1, 2]], 'a' => 'é/x', 10 => 1.0, 9 => 'n']);

        self::assertSame('{"10":1.0,"9":"n","a":"é/x","b":{"a":[3,1,2],"z":1}}', $json);
    }

    public function testScalarsAndEmptyArray(): void
    {
        $json = new CanonicalJson();

        self::assertSame('"x"', $json->encode('x'));
        self::assertSame('[]', $json->encode([]));
        self::assertSame('null', $json->encode(null));
    }

    public function testInvalidUtf8Throws(): void
    {
        $this->expectException(\JsonException::class);

        (new CanonicalJson())->encode(["\xB1\x31"]);
    }
}
