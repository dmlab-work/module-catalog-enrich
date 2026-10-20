<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\ResourceModel\Attempt\Grid;

use DmLab\CatalogEnrich\Model\ResourceModel\Attempt\Grid\Collection;
use PHPUnit\Framework\TestCase;

class CollectionTest extends TestCase
{
    public function testFilterMapQualifiesEveryGridField(): void
    {
        self::assertSame('cpe.sku', Collection::FILTER_MAP['sku']);
        self::assertSame('pn.value', Collection::FILTER_MAP['product_name']);
        self::assertSame('ea.frontend_label', Collection::FILTER_MAP['attribute_label']);
        $columns = ['attempt_id', 'product_id', 'attribute_code', 'outcome', 'attempts', 'error_message', 'model',
            'attempted_at', 'next_attempt_at'];
        foreach ($columns as $field) {
            self::assertSame('main_table.' . $field, Collection::FILTER_MAP[$field]);
        }
    }
}
