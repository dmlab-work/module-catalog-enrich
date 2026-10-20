<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Queue;

use DmLab\CatalogEnrich\Console\Command\RunCommand;
use DmLab\CatalogEnrich\Model\Queue\EnrichConsumer;
use DmLab\CatalogEnrich\Model\ResourceModel\DeferredQueue;
use DmLab\CatalogEnrich\Model\Run\Enricher;
use DmLab\CatalogEnrich\Model\Run\LastRunStore;
use DmLab\CatalogEnrich\Model\Run\RunOptions;
use DmLab\CatalogEnrich\Model\Run\RunReport;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EnrichConsumerTest extends TestCase
{
    public function testCastsAndDedupsIdsAndRunsWithDefaultOptions(): void
    {
        $enricher = $this->createMock(Enricher::class);
        $enricher->expects($this->once())
            ->method('run')
            ->with(
                [5, 7, 9],
                $this->callback(static fn (RunOptions $options): bool => $options->getAttributeCode() === null
                    && !$options->isForce()
                    && !$options->isDryRun()
                    && $options->getConsumer() === RunOptions::DEFAULT_CONSUMER)
            )
            ->willReturn(new RunReport());
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');
        $deferred = $this->createMock(DeferredQueue::class);
        $deferred->expects($this->never())->method('add');

        (new EnrichConsumer($enricher, $this->freeLock(), $logger, $deferred, $this->lastRuns()))
            ->process(['5', 7, '7', 5, 9, 'abc', 0, -3, null]);
    }

    public function testNoValidIdsSkipsTheRun(): void
    {
        $enricher = $this->createMock(Enricher::class);
        $enricher->expects($this->never())->method('run');

        $consumer = new EnrichConsumer(
            $enricher,
            $this->freeLock(),
            $this->createStub(LoggerInterface::class),
            $this->createStub(DeferredQueue::class),
            $this->lastRuns()
        );
        $consumer->process(['x', 0]);
    }

    public function testStopReasonIsLoggedAndIdsAreDeferred(): void
    {
        $report = new RunReport();
        $report->setStopReason('Budget exceeded.');
        $enricher = $this->createStub(Enricher::class);
        $enricher->method('run')->willReturn($report);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Budget exceeded.'), ['product_ids' => [3, 4]]);
        $deferred = $this->createMock(DeferredQueue::class);
        $deferred->expects($this->once())->method('add')->with([3, 4]);

        (new EnrichConsumer($enricher, $this->freeLock(), $logger, $deferred, $this->lastRuns()))->process([3, 4]);
    }

    public function testExceptionIsLoggedAndRethrown(): void
    {
        $error = new \RuntimeException('DB gone');
        $enricher = $this->createStub(Enricher::class);
        $enricher->method('run')->willThrowException($error);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('DB gone'), ['product_ids' => [1, 2], 'exception' => $error]);
        $deferred = $this->createMock(DeferredQueue::class);
        $deferred->expects($this->never())->method('add');

        try {
            (new EnrichConsumer($enricher, $this->freeLock(), $logger, $deferred, $this->lastRuns()))->process([1, 2]);
            self::fail('Exception expected.');
        } catch (\RuntimeException $e) {
            self::assertSame($error, $e);
        }
    }

    public function testErrorIsRethrownAsAnException(): void
    {
        $error = new \TypeError('bad');
        $enricher = $this->createStub(Enricher::class);
        $enricher->method('run')->willThrowException($error);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        try {
            $deferred = $this->createStub(DeferredQueue::class);
            (new EnrichConsumer($enricher, $this->freeLock(), $logger, $deferred, $this->lastRuns()))->process([1]);
            self::fail('Exception expected.');
        } catch (LocalizedException $e) {
            self::assertSame('bad', $e->getMessage());
        }
    }

    public function testRunHoldsTheSharedLockAndReleasesIt(): void
    {
        $lock = $this->createMock(LockManagerInterface::class);
        $lock->expects($this->once())->method('lock')->with(RunCommand::LOCK_NAME, -1)->willReturn(true);
        $lock->expects($this->once())->method('unlock')->with(RunCommand::LOCK_NAME);
        $enricher = $this->createStub(Enricher::class);
        $enricher->method('run')->willThrowException(new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);
        (new EnrichConsumer(
            $enricher,
            $lock,
            $this->createStub(LoggerInterface::class),
            $this->createStub(DeferredQueue::class),
            $this->lastRuns()
        ))->process([1]);
    }

    public function testLockUnavailableDefersTheIds(): void
    {
        $lock = $this->createMock(LockManagerInterface::class);
        $lock->method('lock')->willReturn(false);
        $lock->expects($this->never())->method('unlock');
        $enricher = $this->createMock(Enricher::class);
        $enricher->expects($this->never())->method('run');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('run lock unavailable'));
        $deferred = $this->createMock(DeferredQueue::class);
        $deferred->expects($this->once())->method('add')->with([1]);

        (new EnrichConsumer($enricher, $lock, $logger, $deferred, $this->lastRuns()))->process([1]);
    }

    private function lastRuns(): LastRunStore
    {
        return $this->createStub(LastRunStore::class);
    }

    public function testRunIsAddedToTheQueueSummaryUnderTheLock(): void
    {
        $report = new RunReport();
        $report->setStopReason('Budget exceeded.');
        $enricher = $this->createStub(Enricher::class);
        $enricher->method('run')->willReturn($report);
        $locked = false;
        $lock = $this->createStub(LockManagerInterface::class);
        $lock->method('lock')->willReturnCallback(function () use (&$locked): bool {
            return $locked = true;
        });
        $lock->method('unlock')->willReturnCallback(function () use (&$locked): bool {
            $locked = false;

            return true;
        });
        $lastRuns = $this->createMock(LastRunStore::class);
        $lastRuns->expects($this->never())->method('save');
        $lastRuns->expects($this->once())->method('addQueueRun')
            ->with($report, 2)
            ->willReturnCallback(function () use (&$locked): void {
                self::assertTrue($locked);
            });

        (new EnrichConsumer(
            $enricher,
            $lock,
            $this->createStub(LoggerInterface::class),
            $this->createStub(DeferredQueue::class),
            $lastRuns
        ))->process([3, '7', 3]);
    }

    public function testNoRunStoresNoSummary(): void
    {
        $lock = $this->createStub(LockManagerInterface::class);
        $lock->method('lock')->willReturn(false);
        $lastRuns = $this->createMock(LastRunStore::class);
        $lastRuns->expects($this->never())->method('addQueueRun');

        (new EnrichConsumer(
            $this->createStub(Enricher::class),
            $lock,
            $this->createStub(LoggerInterface::class),
            $this->createStub(DeferredQueue::class),
            $lastRuns
        ))->process([3]);
    }

    private function freeLock(): LockManagerInterface
    {
        $lock = $this->createStub(LockManagerInterface::class);
        $lock->method('lock')->willReturn(true);

        return $lock;
    }
}
