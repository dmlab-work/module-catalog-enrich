<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Cron;

use DmLab\CatalogEnrich\Console\Command\RunCommand;
use DmLab\CatalogEnrich\Model\Config;
use DmLab\CatalogEnrich\Model\Run\Enricher;
use DmLab\CatalogEnrich\Model\Run\LastRunStore;
use DmLab\CatalogEnrich\Model\Run\ProductSelector;
use DmLab\CatalogEnrich\Model\Run\RunOptions;
use DmLab\CatalogEnrich\Model\Run\RunReport;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Nightly job: from a saved cursor, enriches until `products_per_run` products produced a request.
 *
 * Unchanged products cost no request, so scanning goes on past them, bounded by SCAN_FACTOR × cap products and
 * one wrap around the end of the catalog.
 */
class NightlyEnrich
{
    public const CURSOR_FLAG = 'dmlab_catalog_enrich_cursor';
    public const SCAN_FACTOR = 20;

    /**
     * @param Config $config
     * @param ProductSelector $productSelector
     * @param Enricher $enricher
     * @param FlagManager $flagManager
     * @param LockManagerInterface $lockManager
     * @param LoggerInterface $logger
     * @param LastRunStore $lastRunStore
     */
    public function __construct(
        private readonly Config $config,
        private readonly ProductSelector $productSelector,
        private readonly Enricher $enricher,
        private readonly FlagManager $flagManager,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger,
        private readonly LastRunStore $lastRunStore
    ) {
    }

    /**
     * Run one nightly batch.
     *
     * @return void
     */
    public function execute(): void
    {
        if (!$this->config->isCronEnabled()) {
            return;
        }
        if (!$this->lockManager->lock(RunCommand::LOCK_NAME, 0)) {
            $this->logger->info('DmLab_CatalogEnrich: nightly run skipped, another enrichment run is in progress.');
            return;
        }
        try {
            $this->runBatch();
        } finally {
            $this->lockManager->unlock(RunCommand::LOCK_NAME);
        }
    }

    /**
     * Scan pages after the cursor until the cap of asked products, the scan bound or the starting point is reached.
     *
     * @return void
     */
    private function runBatch(): void
    {
        $cap = $this->config->getCronProductsPerRun();
        $scanBound = $cap * self::SCAN_FACTOR;
        $start = $this->getCursor();
        $cursor = $start;
        $wrapped = false;
        $scanned = 0;
        $report = new RunReport();
        $ran = false;

        while ($scanned < $scanBound) {
            $pageSize = min($cap, $scanBound - $scanned);
            $productIds = $this->productSelector->select(null, $pageSize, $cursor);
            $endOfCatalog = count($productIds) < $pageSize;
            if ($wrapped) {
                // Back at the starting point: everything was looked at once.
                $kept = array_values(array_filter($productIds, static fn (int $id): bool => $id <= $start));
                $endOfCatalog = $endOfCatalog || count($kept) < count($productIds);
                $productIds = $kept;
            }
            if ($productIds !== []) {
                $ran = true;
                try {
                    $batch = $this->enricher->run(
                        $productIds,
                        new RunOptions(productLimit: $cap - $report->getAskedProducts())
                    );
                } catch (\Throwable $e) {
                    $this->flagManager->saveFlag(self::CURSOR_FLAG, $endOfCatalog ? 0 : (int)end($productIds));
                    throw $e;
                }
                $report->merge($batch);
                if ($batch->getStopReason() !== null) {
                    $report->setStopReason($batch->getStopReason());
                    $this->lastRunStore->save(LastRunStore::SOURCE_CRON, $report);
                    $this->logger->info('DmLab_CatalogEnrich: nightly run finished. ' . $this->summary($report));
                    // Budget, circuit or config stop: keep the cursor before this page so it is retried, not skipped.
                    $this->logger->warning('DmLab_CatalogEnrich: nightly run stopped: ' . $batch->getStopReason());
                    if ($cursor !== $start) {
                        $this->flagManager->saveFlag(self::CURSOR_FLAG, $cursor);
                    }
                    return;
                }
                if ($batch->isLimitReached()) {
                    $cursor = (int)$batch->getLastProductId();
                    break;
                }
                $scanned += count($productIds);
                $cursor = (int)end($productIds);
                if ($report->getAskedProducts() >= $cap) {
                    break;
                }
            }
            if (!$endOfCatalog) {
                continue;
            }
            if ($wrapped || $start === 0) {
                $cursor = $wrapped ? $cursor : 0;
                break;
            }
            $wrapped = true;
            $cursor = 0;
        }

        if ($ran) {
            $this->lastRunStore->save(LastRunStore::SOURCE_CRON, $report);
            $this->logger->info('DmLab_CatalogEnrich: nightly run finished. ' . $this->summary($report));
        }
        $this->flagManager->saveFlag(self::CURSOR_FLAG, $cursor);
    }

    /**
     * Last processed product id; 0 when unset.
     *
     * @return int
     */
    private function getCursor(): int
    {
        $value = $this->flagManager->getFlagData(self::CURSOR_FLAG);

        return is_numeric($value) && (int)$value > 0 ? (int)$value : 0;
    }

    /**
     * One-line run counters.
     *
     * @param RunReport $report
     * @return string
     */
    private function summary(RunReport $report): string
    {
        $parts = [];
        foreach ($report->getCounters() as $counter => $value) {
            $parts[] = $counter . ' ' . $value;
        }
        foreach ($report->getSuggestions() as $status => $count) {
            $parts[] = 'suggestions ' . $status . ' ' . $count;
        }

        return implode(', ', $parts);
    }
}
