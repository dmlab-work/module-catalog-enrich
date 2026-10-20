<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Run;

use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Compact summary of the last run per source, one flag each, shown above the admin grids.
 *
 * The queue summary covers the whole last product grid mass action: its messages add up into it.
 */
class LastRunStore
{
    public const SOURCE_CLI = 'cli';
    public const SOURCE_CRON = 'cron';
    public const SOURCE_QUEUE = 'queue';
    public const SOURCES = [self::SOURCE_CLI, self::SOURCE_CRON, self::SOURCE_QUEUE];
    public const FLAG_PREFIX = 'dmlab_catalog_enrich_last_run_';

    /**
     * Summary key => run counter.
     */
    private const COUNTERS = [
        'products' => RunReport::PRODUCTS,
        'requests' => RunReport::REQUESTS,
        'tokens' => RunReport::INPUT_TOKENS,
        'accepted' => RunReport::ACCEPTED,
        'not_stated' => RunReport::NOT_STATED,
        'uncertain' => RunReport::UNCERTAIN,
        'error' => RunReport::ERRORS,
        'rejected' => RunReport::REJECTED,
    ];

    /**
     * @param FlagManager $flagManager
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Store the summary of a finished or stopped run.
     *
     * @param string $source one of the SOURCE_* constants
     * @param RunReport $report
     * @return void
     */
    public function save(string $source, RunReport $report): void
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown run source "%s".', $source));
        }
        $summary = ['time' => (int)$this->dateTime->gmtTimestamp()];
        foreach (self::COUNTERS as $key => $counter) {
            $summary[$key] = $report->get($counter);
        }
        $summary['stop_reason'] = $report->getStopReason();
        $this->store($source, $summary);
    }

    /**
     * Reset the queue summary for a new mass action of `$products` queued products.
     *
     * @param int $products
     * @return void
     */
    public function startQueue(int $products): void
    {
        $now = (int)$this->dateTime->gmtTimestamp();
        $summary = ['time' => $now, 'queued_at' => $now, 'queued' => $products, 'processed' => 0];
        $summary += array_fill_keys(array_keys(self::COUNTERS), 0);
        $summary['stop_reason'] = null;
        $this->store(self::SOURCE_QUEUE, $summary);
    }

    /**
     * Add one processed queue message to the queue summary; the stop reason is the latest message's.
     *
     * @param RunReport $report
     * @param int $products product ids of the message
     * @return void
     */
    public function addQueueRun(RunReport $report, int $products): void
    {
        try {
            $data = $this->flagManager->getFlagData(self::FLAG_PREFIX . self::SOURCE_QUEUE);
        } catch (\Throwable $e) {
            $data = null;
        }
        $data = is_array($data) ? $data : [];
        $summary = [
            'time' => (int)$this->dateTime->gmtTimestamp(),
            'queued_at' => is_numeric($data['queued_at'] ?? null) ? (int)$data['queued_at'] : null,
            'queued' => (int)($data['queued'] ?? 0),
            'processed' => (int)($data['processed'] ?? 0) + $products,
        ];
        foreach (self::COUNTERS as $key => $counter) {
            $summary[$key] = (is_numeric($data[$key] ?? null) ? (int)$data[$key] : 0) + $report->get($counter);
        }
        $summary['stop_reason'] = $report->getStopReason();
        $this->store(self::SOURCE_QUEUE, $summary);
    }

    /**
     * Stored summaries by source, in SOURCES order; sources that never ran are left out.
     *
     * The queue summary also has `queued_at` (null before the first mass action), `queued` and `processed`.
     *
     * @return array<string, array{time: int, products: int, requests: int, tokens: int, accepted: int,
     *     not_stated: int, uncertain: int, error: int, rejected: int, stop_reason: string|null}>
     */
    public function getAll(): array
    {
        $result = [];
        foreach (self::SOURCES as $source) {
            $data = $this->flagManager->getFlagData(self::FLAG_PREFIX . $source);
            if (!is_array($data) || !is_numeric($data['time'] ?? null)) {
                continue;
            }
            $summary = ['time' => (int)$data['time']];
            foreach (array_keys(self::COUNTERS) as $key) {
                $summary[$key] = is_numeric($data[$key] ?? null) ? (int)$data[$key] : 0;
            }
            $reason = $data['stop_reason'] ?? null;
            $summary['stop_reason'] = is_string($reason) && $reason !== '' ? $reason : null;
            if ($source === self::SOURCE_QUEUE) {
                $summary['queued_at'] = is_numeric($data['queued_at'] ?? null) ? (int)$data['queued_at'] : null;
                $summary['queued'] = is_numeric($data['queued'] ?? null) ? (int)$data['queued'] : 0;
                $summary['processed'] = is_numeric($data['processed'] ?? null) ? (int)$data['processed'] : 0;
            }
            $result[$source] = $summary;
        }

        return $result;
    }

    /**
     * Write one summary flag; a failure is only logged, the run itself is done.
     *
     * @param string $source
     * @param array<string,mixed> $summary
     * @return void
     */
    private function store(string $source, array $summary): void
    {
        try {
            $this->flagManager->saveFlag(self::FLAG_PREFIX . $source, $summary);
        } catch (\Throwable $e) {
            $this->logger->warning('DmLab_CatalogEnrich: could not store the last run summary: ' . $e->getMessage());
        }
    }
}
