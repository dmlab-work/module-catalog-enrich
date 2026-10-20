<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Queue;

use DmLab\CatalogEnrich\Console\Command\RunCommand;
use DmLab\CatalogEnrich\Model\ResourceModel\DeferredQueue;
use DmLab\CatalogEnrich\Model\Run\Enricher;
use DmLab\CatalogEnrich\Model\Run\LastRunStore;
use DmLab\CatalogEnrich\Model\Run\RunOptions;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs enrichment for product ids queued from the admin product grid.
 */
class EnrichConsumer
{
    public const TOPIC = 'dmlab.catalog_enrich.products';

    /**
     * @param Enricher $enricher
     * @param LockManagerInterface $lockManager
     * @param LoggerInterface $logger
     * @param DeferredQueue $deferredQueue
     * @param LastRunStore $lastRunStore
     */
    public function __construct(
        private readonly Enricher $enricher,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger,
        private readonly DeferredQueue $deferredQueue,
        private readonly LastRunStore $lastRunStore
    ) {
    }

    /**
     * Enrich one queued batch under the shared run lock, waiting for a CLI/cron run to finish.
     *
     * A stopped run (budget, key, circuit) defers the ids for the hourly requeue cron; an unexpected failure is
     * rethrown so the message ends in the queue's error status instead of looking processed.
     *
     * @param array<int|string,mixed> $productIds
     * @return void
     * @throws \Exception
     */
    public function process(array $productIds): void
    {
        $ids = [];
        foreach ($productIds as $id) {
            if (is_numeric($id) && (int)$id > 0) {
                $ids[(int)$id] = (int)$id;
            }
        }
        if (!$ids) {
            return;
        }

        $locked = false;
        try {
            $locked = $this->lockManager->lock(RunCommand::LOCK_NAME, -1);
            if (!$locked) {
                $this->logger->warning(
                    'DmLab_CatalogEnrich: queued enrichment deferred, run lock unavailable.',
                    ['product_ids' => array_values($ids)]
                );
                $this->deferredQueue->add(array_values($ids));
                return;
            }
            $report = $this->enricher->run(array_values($ids), new RunOptions());
            // Under the run lock, so concurrent consumers add up their messages one at a time.
            $this->lastRunStore->addQueueRun($report, count($ids));
        } catch (\Throwable $e) {
            $this->logger->warning(
                'DmLab_CatalogEnrich: queued enrichment failed: ' . $e->getMessage(),
                ['product_ids' => array_values($ids), 'exception' => $e]
            );
            // The queue only rejects \Exception; an \Error would kill the consumer process (it is logged above).
            throw $e instanceof \Exception ? $e : new LocalizedException(__('%1', $e->getMessage()));
        } finally {
            if ($locked) {
                $this->lockManager->unlock(RunCommand::LOCK_NAME);
            }
        }

        if ($report->getStopReason() !== null) {
            $this->logger->warning(
                'DmLab_CatalogEnrich: queued enrichment stopped, deferred: ' . $report->getStopReason(),
                ['product_ids' => array_values($ids)]
            );
            // Already answered pairs are skipped by the ledger when these come back.
            $this->deferredQueue->add(array_values($ids));
        }
    }
}
