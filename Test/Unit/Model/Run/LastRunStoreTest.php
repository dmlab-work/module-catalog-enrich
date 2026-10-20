<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Run;

use DmLab\CatalogEnrich\Model\Run\LastRunStore;
use DmLab\CatalogEnrich\Model\Run\RunReport;
use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LastRunStoreTest extends TestCase
{
    private function dateTime(): DateTime
    {
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1760954400);

        return $dateTime;
    }

    public function testSavesACompactSummaryUnderTheSourceFlag(): void
    {
        $report = new RunReport();
        $report->increment(RunReport::PRODUCTS, 12);
        $report->increment(RunReport::REQUESTS, 8);
        $report->increment(RunReport::INPUT_TOKENS, 6100);
        $report->increment(RunReport::ACCEPTED, 5);
        $report->increment(RunReport::NOT_STATED, 2);
        $report->increment(RunReport::ERRORS, 1);
        $report->setStopReason('Budget exceeded.');
        $flags = $this->createMock(FlagManager::class);
        $flags->expects(self::once())->method('saveFlag')->with('dmlab_catalog_enrich_last_run_cron', [
            'time' => 1760954400,
            'products' => 12,
            'requests' => 8,
            'tokens' => 6100,
            'accepted' => 5,
            'not_stated' => 2,
            'uncertain' => 0,
            'error' => 1,
            'rejected' => 0,
            'stop_reason' => 'Budget exceeded.',
        ]);

        (new LastRunStore($flags, $this->dateTime(), $this->createStub(LoggerInterface::class)))
            ->save(LastRunStore::SOURCE_CRON, $report);
    }

    public function testSaveFailureIsOnlyLogged(): void
    {
        $flags = $this->createStub(FlagManager::class);
        $flags->method('saveFlag')->willThrowException(new \RuntimeException('DB gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with($this->stringContains('DB gone'));

        (new LastRunStore($flags, $this->dateTime(), $logger))->save(LastRunStore::SOURCE_CLI, new RunReport());
    }

    public function testUnknownSourceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LastRunStore(
            $this->createStub(FlagManager::class),
            $this->dateTime(),
            $this->createStub(LoggerInterface::class)
        ))->save('web', new RunReport());
    }

    public function testStartQueueResetsTheQueueSummary(): void
    {
        $flags = $this->createMock(FlagManager::class);
        $flags->expects(self::once())->method('saveFlag')->with('dmlab_catalog_enrich_last_run_queue', [
            'time' => 1760954400,
            'queued_at' => 1760954400,
            'queued' => 250,
            'processed' => 0,
            'products' => 0,
            'requests' => 0,
            'tokens' => 0,
            'accepted' => 0,
            'not_stated' => 0,
            'uncertain' => 0,
            'error' => 0,
            'rejected' => 0,
            'stop_reason' => null,
        ]);

        (new LastRunStore($flags, $this->dateTime(), $this->createStub(LoggerInterface::class)))->startQueue(250);
    }

    public function testQueueMessagesAddUpIntoTheMassActionSummary(): void
    {
        $stored = null;
        $flags = $this->createStub(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(function () use (&$stored): mixed {
            return $stored;
        });
        $flags->method('saveFlag')->willReturnCallback(function (string $code, array $data) use (&$stored): bool {
            $stored = $data;

            return true;
        });
        $store = new LastRunStore($flags, $this->dateTime(), $this->createStub(LoggerInterface::class));
        $first = new RunReport();
        $first->increment(RunReport::PRODUCTS, 90);
        $first->increment(RunReport::ACCEPTED, 4);
        $first->setStopReason('Circuit open.');
        $second = new RunReport();
        $second->increment(RunReport::PRODUCTS, 40);
        $second->increment(RunReport::ACCEPTED, 1);
        $second->increment(RunReport::REJECTED, 2);

        $store->startQueue(150);
        $store->addQueueRun($first, 100);
        $store->addQueueRun($second, 50);
        $run = $store->getAll()['queue'];

        self::assertSame(150, $run['queued']);
        self::assertSame(1760954400, $run['queued_at']);
        self::assertSame(150, $run['processed']);
        self::assertSame(130, $run['products']);
        self::assertSame(5, $run['accepted']);
        self::assertSame(2, $run['rejected']);
        self::assertNull($run['stop_reason']);
    }

    public function testQueueRunWithoutAStartedMassActionStartsFromZero(): void
    {
        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturn(null);
        $flags->expects(self::once())->method('saveFlag')->with(
            'dmlab_catalog_enrich_last_run_queue',
            self::callback(static fn (array $data): bool => $data['queued_at'] === null
                && $data['queued'] === 0 && $data['processed'] === 3 && $data['stop_reason'] === null)
        );

        (new LastRunStore($flags, $this->dateTime(), $this->createStub(LoggerInterface::class)))
            ->addQueueRun(new RunReport(), 3);
    }

    public function testGetAllSkipsMissingAndNormalizesStoredData(): void
    {
        $flags = $this->createStub(FlagManager::class);
        $flags->method('getFlagData')->willReturnMap([
            ['dmlab_catalog_enrich_last_run_cli', ['time' => '100', 'products' => '3', 'stop_reason' => '']],
            ['dmlab_catalog_enrich_last_run_cron', null],
            ['dmlab_catalog_enrich_last_run_queue', ['time' => 200, 'requests' => 2, 'stop_reason' => 'Stop.']],
        ]);

        $runs = (new LastRunStore($flags, $this->dateTime(), $this->createStub(LoggerInterface::class)))->getAll();

        self::assertSame(['cli', 'queue'], array_keys($runs));
        self::assertSame(100, $runs['cli']['time']);
        self::assertSame(3, $runs['cli']['products']);
        self::assertSame(0, $runs['cli']['tokens']);
        self::assertNull($runs['cli']['stop_reason']);
        self::assertSame(2, $runs['queue']['requests']);
        self::assertSame('Stop.', $runs['queue']['stop_reason']);
        self::assertNull($runs['queue']['queued_at']);
        self::assertSame(0, $runs['queue']['queued']);
        self::assertArrayNotHasKey('queued', $runs['cli']);
    }
}
