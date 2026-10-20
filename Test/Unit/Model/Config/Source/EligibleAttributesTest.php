<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Config\Source;

use DmLab\CatalogEnrich\Model\Attribute\AttributeCatalog;
use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\Config\Source\EligibleAttributes;
use PHPUnit\Framework\TestCase;

class EligibleAttributesTest extends TestCase
{
    public function testOptionArray(): void
    {
        $catalog = $this->createStub(AttributeCatalog::class);
        $catalog->method('getEligible')->willReturn([
            new AttributeDescriptor(1, 'color', 'Color', 'select', 't', [1 => 'Red']),
            new AttributeDescriptor(2, 'is_assembled', 'Is Assembled', 'boolean', 't'),
        ]);

        self::assertSame(
            [
                ['value' => 'color', 'label' => 'Color (color)'],
                ['value' => 'is_assembled', 'label' => 'Is Assembled (is_assembled)'],
            ],
            (new EligibleAttributes($catalog))->toOptionArray()
        );
    }

    public function testEmpty(): void
    {
        $catalog = $this->createStub(AttributeCatalog::class);
        $catalog->method('getEligible')->willReturn([]);

        self::assertSame([], (new EligibleAttributes($catalog))->toOptionArray());
    }
}
