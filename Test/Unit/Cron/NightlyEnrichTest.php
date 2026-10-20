<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Cron;

use DmLab\CatalogEnrich\Console\Command\RunCommand;
use DmLab\CatalogEnrich\Cron\NightlyEnrich;
use DmLab\CatalogEnrich\Model\Config;
use DmLab\CatalogEnrich\Model\Run\Enricher;
use DmLab\CatalogEnrich\Model\Run\LastRunStore;
use DmLab\CatalogEnrich\Model\Run\ProductSelector;
use DmLab\CatalogEnrich\Model\Run\RunOptions;
use DmLab\CatalogEnrich\Model\Run\RunReport;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NightlyEnrichTest extends TestCase
{
    public function testDisabledDoesNothing(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isCronEnabled')->willReturn(false);
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects($this->never())->method('lock');
        $productSelector = $this->createMock(ProductSelector::class);
        $productSelector->expects($this->never())->method('select');
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->expects($this->never())->method('saveFlag');

        $this->cron(
            config: $config,
            productSelector: $productSelector,
            flagManager: $flagManager,
            lockManager: $lockManager
        )->execute();
    }

    public function testLockBusySkips(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects($this->once())->method('lock')->with(RunCommand::LOCK_NAME, 0)->willReturn(false);
        $lockManager->expects($this->never())->method('unlock');
        $productSelector = $this->createMock(ProductSelector::class);
        $productSelector->expects($this->never())->method('select');
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->expects($this->never())->method('saveFlag');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with($this->stringContains('in progress'));

        $this->cron(
            productSelector: $productSelector,
            flagManager: $flagManager,
            lockManager: $lockManager,
            logger: $logger
        )->execute();
    }

    /**
     * Product ids of the in-memory catalog, ascending.
     *
     * @var int[]
     */
    private array $catalog = [];

    /**
     * Product ids that produce a request when enriched.
     *
     * @var int[]
     */
    private array $changed = [];

    /**
     * Each enricher run: [product ids, product limit].
     *
     * @var array<int, array{0: int[], 1: int|null}>
     */
    private array $runs = [];

    /**
     * Selector over $this->catalog.
     *
     * @return ProductSelector
     */
    private function selector(): ProductSelector
    {
        $selector = $this->createStub(ProductSelector::class);
        $selector->method('select')->willReturnCallback(
            function (?array $skus, ?int $limit, int $afterId): array {
                self::assertNull($skus);
                $ids = array_values(array_filter($this->catalog, static fn (int $id): bool => $id > $afterId));

                return array_slice($ids, 0, $limit);
            }
        );

        return $selector;
    }

    /**
     * Enricher asking the changed products, honouring the product limit like the real one.
     *
     * @return Enricher
     */
    private function enricher(): Enricher
    {
        $enricher = $this->createStub(Enricher::class);
        $enricher->method('run')->willReturnCallback(function (array $ids, RunOptions $options): RunReport {
            self::assertNull($options->getAttributeCode());
            self::assertFalse($options->isForce());
            self::assertFalse($options->isDryRun());
            $this->runs[] = [$ids, $options->getProductLimit()];
            $report = new RunReport();
            foreach ($ids as $id) {
                if ($report->getAskedProducts() >= $options->getProductLimit()) {
                    $report->setLimitReached();
                    break;
                }
                $report->setLastProductId($id);
                $report->increment(RunReport::PRODUCTS);
                if (in_array($id, $this->changed, true)) {
                    $report->addAskedProduct();
                    $report->increment(RunReport::REQUESTS);
                }
            }

            return $report;
        });

        return $enricher;
    }

    public function testFullyChangedPageAdvancesTheCursorToTheLastId(): void
    {
        $this->catalog = [5, 11, 14, 20, 30, 31];
        $this->changed = $this->catalog;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with($this->stringContains('products 3, requests 3'));
        $logger->expects($this->never())->method('warning');

        $this->cron(
            productSelector: $this->selector(),
            enricher: $this->enricher(),
            flagManager: $this->flagManager('10', 20),
            lockManager: $this->lockManager(),
            logger: $logger
        )->execute();

        self::assertSame([[[11, 14, 20], 3]], $this->runs);
    }

    public function testCapCountsAskedProductsNotScanned(): void
    {
        $this->catalog = range(1, 12);
        $this->changed = [2, 7, 9, 10, 11];

        $this->cron(
            productSelector: $this->selector(),
            enricher: $this->enricher(),
            flagManager: $this->flagManager(0, 9),
            lockManager: $this->lockManager()
        )->execute();

        self::assertSame([[[1, 2, 3], 3], [[4, 5, 6], 2], [[7, 8, 9], 2]], $this->runs);
    }

    public function testLimitReachedMidPageKeepsTheCursorAtTheLastProductLookedAt(): void
    {
        $this->catalog = range(1, 9);
        $this->changed = [2, 4, 5, 6];

        $this->cron(
            productSelector: $this->selector(),
            enricher: $this->enricher(),
            flagManager: $this->flagManager(0, 5),
            lockManager: $this->lockManager()
        )->execute();

        self::assertSame([[[1, 2, 3], 3], [[4, 5, 6], 2]], $this->runs);
    }

    public function testScanStopsAtTheBound(): void
    {
        $this->catalog = range(1, 200);

        $this->cron(
            productSelector: $this->selector(),
            enricher: $this->enricher(),
            flagManager: $this->flagManager(0, 3 * NightlyEnrich::SCAN_FACTOR),
            lockManager: $this->lockManager()
        )->execute();

        self::assertCount(NightlyEnrich::SCAN_FACTOR, $this->runs);
    }

    public function testEndOfCatalogWrapsOnceAndStopsAtTheStart(): void
    {
        $this->catalog = range(1, 10);
        $this->changed = [];

        $this->cron(
            productSelector: $this->selector(),
            enricher: $this->enricher(),
            flagManager: $this->flagManager(6, 6),
            lockManager: $this->lockManager()
        )->execute();

        self::assertSame([[7, 8, 9], [10], [1, 2, 3], [4, 5, 6]], array_column($this->runs, 0));
    }

    public function testWrapContinuesUntilTheCapIsReached(): void
    {
        $this->catalog = range(1, 10);
        $this->changed = [9, 2, 3, 4];

        $this->cron(
            productSelector: $this->selector(),
            enricher: $this->enricher(),
            flagManager: $this->flagManager(6, 3),
            lockManager: $this->lockManager()
        )->execute();

        self::assertSame([[7, 8, 9], [10], [1, 2, 3]], array_column($this->runs, 0));
    }

    public function testShortCatalogFromZeroWrapsTheCursorToZero(): void
    {
        $this->catalog = [25];

        $this->cron(
            productSelector: $this->selector(),
            enricher: $this->enricher(),
            flagManager: $this->flagManager(null, 0),
            lockManager: $this->lockManager()
        )->execute();

        self::assertSame([[25]], array_column($this->runs, 0));
    }

    public function testEmptySelectionWrapsWithoutARun(): void
    {
        $enricher = $this->createMock(Enricher::class);
        $enricher->expects($this->never())->method('run');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');

        $this->cron(
            productSelector: $this->selector(),
            enricher: $enricher,
            flagManager: $this->flagManager(99, 0),
            lockManager: $this->lockManager(),
            logger: $logger
        )->execute();
    }

    public function testStopReasonIsLoggedAndTheCursorKept(): void
    {
        $productSelector = $this->createStub(ProductSelector::class);
        $productSelector->method('select')->willReturn([1, 2, 3]);
        $report = new RunReport();
        $report->setStopReason('Budget exceeded.');
        $enricher = $this->createStub(Enricher::class);
        $enricher->method('run')->willReturn($report);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');
        $logger->expects($this->once())->method('warning')->with($this->stringContains('Budget exceeded.'));
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')->willReturn(0);
        $flagManager->expects($this->never())->method('saveFlag');

        $this->cron(
            productSelector: $productSelector,
            enricher: $enricher,
            flagManager: $flagManager,
            logger: $logger
        )->execute();
    }

    public function testFinishedRunStoresTheCronSummary(): void
    {
        $this->catalog = [5, 11];
        $this->changed = [5, 11];
        $lastRuns = $this->createMock(LastRunStore::class);
        $lastRuns->expects($this->once())->method('save')->with(
            LastRunStore::SOURCE_CRON,
            $this->callback(static fn (RunReport $report): bool => $report->get(RunReport::REQUESTS) === 2
                && $report->getStopReason() === null)
        );

        $this->cron(
            productSelector: $this->selector(),
            enricher: $this->enricher(),
            flagManager: $this->flagManager(0, 0),
            lastRunStore: $lastRuns
        )->execute();
    }

    public function testStoppedRunStoresTheCronSummaryWithTheReason(): void
    {
        $this->catalog = range(1, 9);
        $enricher = $this->createStub(Enricher::class);
        $enricher->method('run')->willReturnCallback(function (array $ids): RunReport {
            $report = new RunReport();
            $report->increment(RunReport::PRODUCTS, count($ids));
            if ($ids[0] === 4) {
                $report->setStopReason('Budget exceeded.');
            }

            return $report;
        });
        $lastRuns = $this->createMock(LastRunStore::class);
        $lastRuns->expects($this->once())->method('save')->with(
            LastRunStore::SOURCE_CRON,
            $this->callback(static fn (RunReport $report): bool => $report->get(RunReport::PRODUCTS) === 6
                && $report->getStopReason() === 'Budget exceeded.')
        );

        $this->cron(
            productSelector: $this->selector(),
            enricher: $enricher,
            flagManager: $this->flagManager(0, 3),
            lastRunStore: $lastRuns
        )->execute();
    }

    public function testNothingToScanStoresNoSummary(): void
    {
        $lastRuns = $this->createMock(LastRunStore::class);
        $lastRuns->expects($this->never())->method('save');

        $this->cron(
            productSelector: $this->selector(),
            enricher: $this->enricher(),
            flagManager: $this->flagManager(0, 0),
            lastRunStore: $lastRuns
        )->execute();
    }

    public function testStopOnALaterPageSavesTheCursorBeforeThatPage(): void
    {
        $this->catalog = range(1, 9);
        $enricher = $this->createStub(Enricher::class);
        $enricher->method('run')->willReturnCallback(function (array $ids): RunReport {
            $report = new RunReport();
            if ($ids[0] === 4) {
                $report->setStopReason('Budget exceeded.');
            }

            return $report;
        });

        $this->cron(
            productSelector: $this->selector(),
            enricher: $enricher,
            flagManager: $this->flagManager(0, 3),
            lockManager: $this->lockManager()
        )->execute();
    }

    public function testUnlocksAndSavesTheCursorWhenTheRunThrows(): void
    {
        $productSelector = $this->createStub(ProductSelector::class);
        $productSelector->method('select')->willReturn([1, 2, 3]);
        $enricher = $this->createStub(Enricher::class);
        $enricher->method('run')->willThrowException(new \RuntimeException('DB gone'));

        $this->expectException(\RuntimeException::class);
        $this->cron(
            productSelector: $productSelector,
            enricher: $enricher,
            flagManager: $this->flagManager(0, 3),
            lockManager: $this->lockManager()
        )->execute();
    }

    /**
     * Flag manager returning the stored cursor and expecting exactly one save of the next one.
     *
     * @param mixed $stored
     * @param int $expectedNext
     * @return FlagManager
     */
    private function flagManager(mixed $stored, int $expectedNext): FlagManager
    {
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')->with(NightlyEnrich::CURSOR_FLAG)->willReturn($stored);
        $flagManager->expects($this->once())->method('saveFlag')->with(NightlyEnrich::CURSOR_FLAG, $expectedNext);

        return $flagManager;
    }

    /**
     * Lock manager expecting one acquire and one release of the shared run lock.
     *
     * @return LockManagerInterface
     */
    private function lockManager(): LockManagerInterface
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects($this->once())->method('lock')->with(RunCommand::LOCK_NAME, 0)->willReturn(true);
        $lockManager->expects($this->once())->method('unlock')->with(RunCommand::LOCK_NAME);

        return $lockManager;
    }

    /**
     * Cron with enabled config (3 per run), a free lock and stubs for anything not given.
     *
     * @param Config|null $config
     * @param ProductSelector|null $productSelector
     * @param Enricher|null $enricher
     * @param FlagManager|null $flagManager
     * @param LockManagerInterface|null $lockManager
     * @param LoggerInterface|null $logger
     * @param LastRunStore|null $lastRunStore
     * @return NightlyEnrich
     */
    private function cron(
        ?Config $config = null,
        ?ProductSelector $productSelector = null,
        ?Enricher $enricher = null,
        ?FlagManager $flagManager = null,
        ?LockManagerInterface $lockManager = null,
        ?LoggerInterface $logger = null,
        ?LastRunStore $lastRunStore = null
    ): NightlyEnrich {
        if ($config === null) {
            $config = $this->createStub(Config::class);
            $config->method('isCronEnabled')->willReturn(true);
            $config->method('getCronProductsPerRun')->willReturn(3);
        }
        if ($lockManager === null) {
            $lockManager = $this->createStub(LockManagerInterface::class);
            $lockManager->method('lock')->willReturn(true);
        }

        return new NightlyEnrich(
            $config,
            $productSelector ?? $this->createStub(ProductSelector::class),
            $enricher ?? $this->createStub(Enricher::class),
            $flagManager ?? $this->createStub(FlagManager::class),
            $lockManager,
            $logger ?? $this->createStub(LoggerInterface::class),
            $lastRunStore ?? $this->createStub(LastRunStore::class)
        );
    }
}
