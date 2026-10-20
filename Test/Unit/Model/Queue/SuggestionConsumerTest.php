<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Queue;

use DmLab\CatalogEnrich\Model\Apply\Applier;
use DmLab\CatalogEnrich\Model\Apply\ApplyResult;
use DmLab\CatalogEnrich\Model\Queue\SuggestionConsumer;
use DmLab\CatalogEnrich\Model\Queue\SuggestionQueueSummary;
use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion as SuggestionResource;
use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion\Collection;
use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion\CollectionFactory;
use DmLab\CatalogEnrich\Model\Suggestion;
use Magento\Framework\Model\Context as ModelContext;
use Magento\Framework\Model\ResourceModel\Db\Context as DbContext;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SuggestionConsumerTest extends TestCase
{
    /**
     * @var string[]
     */
    private array $warnings = [];

    /**
     * @var string[]
     */
    private array $infos = [];

    /**
     * Ids the collection was filtered by.
     *
     * @var int[]|null
     */
    private ?array $filteredIds = null;

    /**
     * Summary additions: [operation, done, refused].
     *
     * @var array<int, array{0: string, 1: int, 2: int}>
     */
    private array $summaries = [];

    private function suggestion(int $id, string $status): Suggestion
    {
        return new Suggestion(
            $this->createStub(ModelContext::class),
            $this->createStub(Registry::class),
            new SuggestionResource($this->createStub(DbContext::class)),
            null,
            ['suggestion_id' => $id, 'product_id' => 10, 'attribute_code' => 'color', 'value' => '5',
             'status' => $status]
        );
    }

    /**
     * @param Suggestion[] $items
     */
    private function consumer(array $items, Applier $applier): SuggestionConsumer
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function (string $field, array $condition) use ($collection): Collection {
                self::assertSame('suggestion_id', $field);
                $this->filteredIds = array_values($condition['in']);
                return $collection;
            }
        );
        $collection->method('getItems')->willReturn($items);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message): void {
            $this->warnings[] = $message;
        });
        $logger->method('info')->willReturnCallback(function (string $message): void {
            $this->infos[] = $message;
        });

        $summary = $this->createStub(SuggestionQueueSummary::class);
        $summary->method('add')->willReturnCallback(function (string $operation, int $done, int $refused): void {
            $this->summaries[] = [$operation, $done, $refused];
        });

        return new SuggestionConsumer($factory, $applier, $logger, $summary);
    }

    public function testApprovesPendingAndLogsRefusals(): void
    {
        $ok = $this->suggestion(1, Suggestion::STATUS_PENDING);
        $filled = $this->suggestion(2, Suggestion::STATUS_PENDING);
        $applied = $this->suggestion(3, Suggestion::STATUS_APPLIED);
        $applier = $this->createMock(Applier::class);
        $applier->expects(self::never())->method('revert');
        $applier->expects(self::exactly(2))->method('apply')->willReturnCallback(
            static fn (Suggestion $s): ApplyResult => $s === $ok
                ? new ApplyResult(true)
                : new ApplyResult(false, 'The attribute already has a value.')
        );

        $this->consumer([$ok, $filled, $applied], $applier)
            ->process('{"operation":"approve","ids":[1,2,3,4,"2",0,-1,"x"]}');

        self::assertSame([1, 2, 3, 4], $this->filteredIds);
        self::assertSame([
            'DmLab_CatalogEnrich: queued approve of suggestion #2 refused: The attribute already has a value.',
            'DmLab_CatalogEnrich: queued approve of suggestion #3 refused: '
                . 'status is "applied", only "pending" can be processed.',
            'DmLab_CatalogEnrich: queued approve of suggestion #4 refused: The suggestion no longer exists.',
        ], $this->warnings);
        self::assertSame(['DmLab_CatalogEnrich: queued approve finished, 1 done, 3 refused.'], $this->infos);
        self::assertSame([['approve', 1, 3]], $this->summaries);
    }

    public function testRevertsOnlyApplied(): void
    {
        $applied = $this->suggestion(7, Suggestion::STATUS_APPLIED);
        $pending = $this->suggestion(8, Suggestion::STATUS_PENDING);
        $applier = $this->createMock(Applier::class);
        $applier->expects(self::never())->method('apply');
        $applier->expects(self::once())->method('revert')->with($applied)->willReturn(new ApplyResult(true));

        $this->consumer([$applied, $pending], $applier)->process('{"operation":"revert","ids":[7,8]}');

        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('#8 refused: status is "pending"', $this->warnings[0]);
        self::assertSame(['DmLab_CatalogEnrich: queued revert finished, 1 done, 1 refused.'], $this->infos);
        self::assertSame([['revert', 1, 1]], $this->summaries);
    }

    /**
     * @return array<string,array{string}>
     */
    public static function malformed(): array
    {
        return [
            'not json' => ['nope'],
            'unknown operation' => ['{"operation":"reject","ids":[1]}'],
            'no ids' => ['{"operation":"approve","ids":[]}'],
            'ids not a list' => ['{"operation":"approve","ids":"1"}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function testMalformedMessageIsLoggedAndDropped(string $message): void
    {
        $applier = $this->createMock(Applier::class);
        $applier->expects(self::never())->method('apply');
        $applier->expects(self::never())->method('revert');

        $this->consumer([], $applier)->process($message);

        self::assertNull($this->filteredIds);
        self::assertSame([], $this->summaries);
        self::assertSame(['DmLab_CatalogEnrich: malformed suggestion queue message dropped.'], $this->warnings);
    }
}
