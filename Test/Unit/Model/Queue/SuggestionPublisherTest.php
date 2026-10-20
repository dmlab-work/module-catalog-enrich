<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Queue;

use DmLab\CatalogEnrich\Model\Queue\SuggestionConsumer;
use DmLab\CatalogEnrich\Model\Queue\SuggestionPublisher;
use DmLab\CatalogEnrich\Model\Queue\SuggestionQueueSummary;
use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\TestCase;

class SuggestionPublisherTest extends TestCase
{
    public function testPublishesJsonChunksOnTheSuggestionTopic(): void
    {
        $published = [];
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects(self::exactly(2))->method('publish')->willReturnCallback(
            function (string $topic, string $message) use (&$published): void {
                self::assertSame(SuggestionConsumer::TOPIC, $topic);
                $published[] = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
            }
        );

        $summary = $this->createMock(SuggestionQueueSummary::class);
        $summary->expects(self::once())->method('start')->with(150)
            ->willReturnCallback(static function () use (&$published): void {
                self::assertSame([], $published);
            });

        (new SuggestionPublisher($publisher, $summary))->publish('revert', [...range(1, 150), 3]);

        self::assertSame(['operation' => 'revert', 'ids' => range(1, 100)], $published[0]);
        self::assertSame(['operation' => 'revert', 'ids' => range(101, 150)], $published[1]);
    }

    public function testUnknownOperationIsRefused(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects(self::never())->method('publish');

        $summary = $this->createMock(SuggestionQueueSummary::class);
        $summary->expects(self::never())->method('start');

        $this->expectException(\InvalidArgumentException::class);
        (new SuggestionPublisher($publisher, $summary))->publish('reject', [1]);
    }
}
