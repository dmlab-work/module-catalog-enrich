<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\ResourceModel\Rule\Grid;

use DmLab\CatalogEnrich\Model\ResourceModel\Rule\Grid\Collection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use PHPUnit\Framework\TestCase;

class CollectionTest extends TestCase
{
    public function testLabelFieldIsTheAttributeCodeForFiltersAndSorting(): void
    {
        $columns = [];
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('columns')->willReturnCallback(function (array $cols) use ($select, &$columns): Select {
            $columns[] = $cols;

            return $select;
        });
        // Only the select matters here: skip the constructor's resource and connection wiring.
        $collection = (new \ReflectionClass(Collection::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(AbstractDb::class, '_select'))->setValue($collection, $select);
        (new \ReflectionProperty(AbstractDb::class, '_conn'))
            ->setValue($collection, $this->createStub(AdapterInterface::class));
        (new \ReflectionProperty(AbstractCollection::class, '_mainTable'))
            ->setValue($collection, 'dmlab_catalog_enrich_rule');

        (new \ReflectionMethod(Collection::class, '_initSelect'))->invoke($collection);

        self::assertContains(['attribute_label' => 'main_table.attribute_code'], $columns);
        self::assertSame(
            'main_table.attribute_code',
            (new \ReflectionMethod(Collection::class, '_getMappedField'))->invoke($collection, 'attribute_label')
        );
    }
}
