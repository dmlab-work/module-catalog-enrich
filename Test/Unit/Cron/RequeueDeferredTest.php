<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Cron;

use DmLab\CatalogEnrich\Controller\Adminhtml\Product\MassEnrich;
use DmLab\CatalogEnrich\Cron\RequeueDeferred;
use DmLab\CatalogEnrich\Model\Queue\EnrichConsumer;
use DmLab\CatalogEnrich\Model\ResourceModel\DeferredQueue;
use DmLab\TypeSafeCore\Api\ConfigInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\TestCase;

class RequeueDeferredTest extends TestCase
{
    public function testPublishesDeferredIdsInMassActionBatches(): void
    {
        $ids = range(1, MassEnrich::CHUNK_SIZE + 5);
        $deferred = $this->createMock(DeferredQueue::class);
        $deferred->expects(self::once())->method('take')->with(RequeueDeferred::LIMIT)->willReturn($ids);
        $published = [];
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects(self::exactly(2))->method('publish')->willReturnCallback(
            function (string $topic, array $chunk) use (&$published): void {
                self::assertSame(EnrichConsumer::TOPIC, $topic);
                $published[] = $chunk;
            }
        );

        (new RequeueDeferred($deferred, $publisher, $this->coreConfig(true)))->execute();

        self::assertSame(array_chunk($ids, MassEnrich::CHUNK_SIZE), $published);
    }

    public function testNothingDeferredPublishesNothing(): void
    {
        $deferred = $this->createStub(DeferredQueue::class);
        $deferred->method('take')->willReturn([]);
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects(self::never())->method('publish');

        (new RequeueDeferred($deferred, $publisher, $this->coreConfig(true)))->execute();
    }

    public function testNotConfiguredLeavesIdsDeferred(): void
    {
        $deferred = $this->createMock(DeferredQueue::class);
        $deferred->expects(self::never())->method('take');
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects(self::never())->method('publish');

        (new RequeueDeferred($deferred, $publisher, $this->coreConfig(false)))->execute();
    }

    private function coreConfig(bool $configured): ConfigInterface
    {
        $config = $this->createStub(ConfigInterface::class);
        $config->method('isConfigured')->willReturn($configured);

        return $config;
    }
}
