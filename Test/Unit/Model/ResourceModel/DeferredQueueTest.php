<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\ResourceModel;

use DmLab\CatalogEnrich\Model\ResourceModel\DeferredQueue;
use PHPUnit\Framework\TestCase;

class DeferredQueueTest extends TestCase
{
    use RecordsSelect;

    /**
     * @var DeferredQueue
     */
    private DeferredQueue $queue;

    protected function setUp(): void
    {
        $this->queue = new DeferredQueue($this->resourceConnection());
    }

    public function testAddUpsertsUniquePositiveIds(): void
    {
        $this->adapter->expects(self::once())->method('insertOnDuplicate')->with(
            'pfx_dmlab_catalog_enrich_deferred',
            [['product_id' => 3], ['product_id' => 4]],
            ['product_id']
        );

        $this->queue->add([3, '4', 3, 0, -2]);
    }

    public function testAddWithoutIdsWritesNothing(): void
    {
        $this->adapter->expects(self::never())->method('insertOnDuplicate');

        $this->queue->add([0]);
    }

    public function testTakeReturnsOldestAndDeletesThem(): void
    {
        $this->adapter->expects(self::once())->method('fetchCol')->willReturn(['7', '9']);
        $this->adapter->expects(self::once())->method('delete')
            ->with('pfx_dmlab_catalog_enrich_deferred', ['product_id IN (?)' => [7, 9]]);

        self::assertSame([7, 9], $this->queue->take(100));
        self::assertSame([['pfx_dmlab_catalog_enrich_deferred', ['product_id']]], $this->callsOf('from'));
        self::assertSame([[['deferred_at ASC', 'product_id ASC']]], $this->callsOf('order'));
        self::assertSame([[100]], $this->callsOf('limit'));
    }

    public function testTakeFromEmptyQueueDeletesNothing(): void
    {
        $this->adapter->method('fetchCol')->willReturn([]);
        $this->adapter->expects(self::never())->method('delete');

        self::assertSame([], $this->queue->take(10));
    }
}
