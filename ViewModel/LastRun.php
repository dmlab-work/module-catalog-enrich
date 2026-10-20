<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\ViewModel;

use DmLab\CatalogEnrich\Model\Queue\SuggestionQueueSummary;
use DmLab\CatalogEnrich\Model\Run\LastRunStore;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Last run per source for the notice above the Rules and Suggestions grids, plus the suggestion queue counters.
 */
class LastRun implements ArgumentInterface
{
    /**
     * @param LastRunStore $lastRunStore
     * @param TimezoneInterface $timezone
     * @param SuggestionQueueSummary $suggestionQueueSummary
     */
    public function __construct(
        private readonly LastRunStore $lastRunStore,
        private readonly TimezoneInterface $timezone,
        private readonly SuggestionQueueSummary $suggestionQueueSummary
    ) {
    }

    /**
     * Display lines, unescaped: source label, admin-local time, counters and the stop reason (null when none).
     *
     * `batch` describes the product grid mass action the queue counters add up (null for other sources).
     *
     * @return array<int, array{source: string, time: string, batch: string|null, summary: string,
     *     stop_reason: string|null}>
     */
    public function getRuns(): array
    {
        $runs = [];
        foreach ($this->lastRunStore->getAll() as $source => $run) {
            $runs[] = [
                'source' => $this->sourceLabel($source),
                'time' => $this->formatTime($run['time']),
                'batch' => isset($run['queued_at']) ? (string)__(
                    'Mass action of %1 product(s) queued at %2; %3 processed so far.',
                    $run['queued'],
                    $this->formatTime($run['queued_at']),
                    $run['processed']
                ) : null,
                'summary' => (string)__(
                    '%1 product(s), %2 request(s), %3 input tokens. Outcomes: accepted %4, not stated %5, '
                    . 'uncertain %6, error %7, rejected by TypeSafe %8.',
                    $run['products'],
                    $run['requests'],
                    $run['tokens'],
                    $run['accepted'],
                    $run['not_stated'],
                    $run['uncertain'],
                    $run['error'],
                    $run['rejected']
                ),
                'stop_reason' => $run['stop_reason'],
            ];
        }

        return $runs;
    }

    /**
     * Last queued approve/revert mass action, unescaped; null when nothing was queued yet.
     *
     * @return array{time: string, summary: string}|null
     */
    public function getSuggestionQueue(): ?array
    {
        $queue = $this->suggestionQueueSummary->get();
        if ($queue === null) {
            return null;
        }
        $counters = (string)__(
            'approved %1, reverted %2, refused %3 (reasons are in the Magento log).',
            $queue['approved'],
            $queue['reverted'],
            $queue['refused']
        );

        return [
            'time' => $this->formatTime($queue['time']),
            'summary' => $queue['queued_at'] === null ? $counters : (string)__(
                '%1 suggestion(s) queued at %2; %3',
                $queue['queued'],
                $this->formatTime($queue['queued_at']),
                $counters
            ),
        ];
    }

    /**
     * Admin-local medium date, short time.
     *
     * @param int $timestamp
     * @return string
     */
    private function formatTime(int $timestamp): string
    {
        return $this->timezone->formatDateTime(
            new \DateTimeImmutable('@' . $timestamp),
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::SHORT
        );
    }

    /**
     * Translated name of a run source.
     *
     * @param string $source
     * @return string
     */
    private function sourceLabel(string $source): string
    {
        return (string)match ($source) {
            LastRunStore::SOURCE_CLI => __('CLI'),
            LastRunStore::SOURCE_CRON => __('Nightly cron'),
            LastRunStore::SOURCE_QUEUE => __('Product grid queue'),
            default => $source,
        };
    }
}
