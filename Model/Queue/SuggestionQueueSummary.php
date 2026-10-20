<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Queue;

use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Counters of the last queued approve/revert mass action, one flag, shown above the Suggestions grid.
 */
class SuggestionQueueSummary
{
    public const FLAG = 'dmlab_catalog_enrich_last_suggestion_queue';

    private const COUNTERS = ['queued', 'approved', 'reverted', 'refused'];

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
     * Reset the counters for a new mass action of `$queued` suggestions.
     *
     * @param int $queued
     * @return void
     */
    public function start(int $queued): void
    {
        $now = (int)$this->dateTime->gmtTimestamp();
        $this->store([
            'time' => $now,
            'queued_at' => $now,
            'queued' => $queued,
            'approved' => 0,
            'reverted' => 0,
            'refused' => 0,
        ]);
    }

    /**
     * Add one processed message.
     *
     * @param string $operation one of the SuggestionConsumer::OPERATION_* constants
     * @param int $done
     * @param int $refused
     * @return void
     */
    public function add(string $operation, int $done, int $refused): void
    {
        $summary = $this->get()
            ?? ['queued_at' => null, 'queued' => 0, 'approved' => 0, 'reverted' => 0, 'refused' => 0];
        $summary['time'] = (int)$this->dateTime->gmtTimestamp();
        $key = $operation === SuggestionConsumer::OPERATION_REVERT ? 'reverted' : 'approved';
        $summary[$key] += $done;
        $summary['refused'] += $refused;
        $this->store($summary);
    }

    /**
     * Stored counters, or null when nothing was queued yet.
     *
     * @return array{time: int, queued_at: int|null, queued: int, approved: int, reverted: int, refused: int}|null
     */
    public function get(): ?array
    {
        try {
            $data = $this->flagManager->getFlagData(self::FLAG);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($data) || !is_numeric($data['time'] ?? null)) {
            return null;
        }
        $summary = [
            'time' => (int)$data['time'],
            'queued_at' => is_numeric($data['queued_at'] ?? null) ? (int)$data['queued_at'] : null,
        ];
        foreach (self::COUNTERS as $key) {
            $summary[$key] = is_numeric($data[$key] ?? null) ? (int)$data[$key] : 0;
        }

        return $summary;
    }

    /**
     * Write the flag; a failure is only logged, the suggestions are processed anyway.
     *
     * @param array<string,mixed> $summary
     * @return void
     */
    private function store(array $summary): void
    {
        try {
            $this->flagManager->saveFlag(self::FLAG, $summary);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'DmLab_CatalogEnrich: could not store the suggestion queue summary: ' . $e->getMessage()
            );
        }
    }
}
