<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Attribute;

use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use PHPUnit\Framework\TestCase;

class AttributeDescriptorTest extends TestCase
{
    public function testAccessors(): void
    {
        $descriptor = new AttributeDescriptor(
            42,
            'color',
            'Color',
            AttributeDescriptor::INPUT_SELECT,
            'catalog_product_entity_int',
            [5 => 'Red', 6 => 'Blue']
        );

        self::assertSame(42, $descriptor->getAttributeId());
        self::assertSame('color', $descriptor->getCode());
        self::assertSame('Color', $descriptor->getLabel());
        self::assertSame('select', $descriptor->getInput());
        self::assertSame('catalog_product_entity_int', $descriptor->getBackendTable());
        self::assertSame([5 => 'Red', 6 => 'Blue'], $descriptor->getOptions());
    }

    public function testOptionsKeepSourceOrderAndDropEmptyAndDuplicateLabels(): void
    {
        $descriptor = new AttributeDescriptor(1, 'material', 'Material', 'multiselect', 't', [
            '9' => ' Oak ',
            3 => 'Pine',
            4 => '',
            5 => '   ',
            7 => 'oak',
            8 => 'PINE ',
            2 => 'Ash',
        ]);

        self::assertSame([9 => 'Oak', 3 => 'Pine', 2 => 'Ash'], $descriptor->getOptions());
    }

    public function testBooleanOptionsAreFixed(): void
    {
        $descriptor = new AttributeDescriptor(1, 'is_assembled', 'Is Assembled', 'boolean', 't', [7 => 'Maybe']);

        self::assertSame([1 => 'Yes', 0 => 'No'], $descriptor->getOptions());
    }

    public function testUnsupportedInputRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AttributeDescriptor(1, 'name', 'Name', 'text', 't');
    }
}
