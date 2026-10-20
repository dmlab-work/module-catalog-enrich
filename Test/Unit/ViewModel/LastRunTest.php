<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\ViewModel;

use DmLab\CatalogEnrich\Model\Queue\SuggestionQueueSummary;
use DmLab\CatalogEnrich\Model\Run\LastRunStore;
use DmLab\CatalogEnrich\ViewModel\LastRun;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\TestCase;

class LastRunTest extends TestCase
{
    private function timezone(): TimezoneInterface
    {
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('formatDateTime')->willReturnCallback(
            static fn (\DateTimeInterface $date): string => $date->format('Y-m-d H:i')
        );

        return $timezone;
    }

    private function viewModel(array $runs, ?array $suggestionQueue = null): LastRun
    {
        $store = $this->createStub(LastRunStore::class);
        $store->method('getAll')->willReturn($runs);
        $summary = $this->createStub(SuggestionQueueSummary::class);
        $summary->method('get')->willReturn($suggestionQueue);

        return new LastRun($store, $this->timezone(), $summary);
    }

    public function testRunsAreFormattedPerSource(): void
    {
        $runs = $this->viewModel([
            'cli' => [
                'time' => 1760954400, 'products' => 12, 'requests' => 8, 'tokens' => 6100, 'accepted' => 5,
                'not_stated' => 2, 'uncertain' => 1, 'error' => 0, 'rejected' => 0, 'stop_reason' => null,
            ],
            'queue' => [
                'time' => 1760958000, 'products' => 0, 'requests' => 0, 'tokens' => 0, 'accepted' => 0,
                'not_stated' => 0, 'uncertain' => 0, 'error' => 0, 'rejected' => 3, 'stop_reason' => 'No key.',
                'queued_at' => 1760956200, 'queued' => 250, 'processed' => 100,
            ],
        ])->getRuns();

        self::assertSame([
            [
                'source' => 'CLI',
                'time' => '2025-10-20 10:00',
                'batch' => null,
                'summary' => '12 product(s), 8 request(s), 6100 input tokens. Outcomes: accepted 5, not stated 2, '
                    . 'uncertain 1, error 0, rejected by TypeSafe 0.',
                'stop_reason' => null,
            ],
            [
                'source' => 'Product grid queue',
                'time' => '2025-10-20 11:00',
                'batch' => 'Mass action of 250 product(s) queued at 2025-10-20 10:30; 100 processed so far.',
                'summary' => '0 product(s), 0 request(s), 0 input tokens. Outcomes: accepted 0, not stated 0, '
                    . 'uncertain 0, error 0, rejected by TypeSafe 3.',
                'stop_reason' => 'No key.',
            ],
        ], $runs);
    }

    public function testQueueWithoutAMassActionHasNoBatchLine(): void
    {
        $runs = $this->viewModel(['queue' => [
            'time' => 1760958000, 'products' => 1, 'requests' => 1, 'tokens' => 1, 'accepted' => 0,
            'not_stated' => 0, 'uncertain' => 0, 'error' => 0, 'rejected' => 0, 'stop_reason' => null,
            'queued_at' => null, 'queued' => 0, 'processed' => 1,
        ]])->getRuns();

        self::assertNull($runs[0]['batch']);
    }

    public function testNoRunsNoLines(): void
    {
        self::assertSame([], $this->viewModel([])->getRuns());
        self::assertNull($this->viewModel([])->getSuggestionQueue());
    }

    public function testSuggestionQueueCounters(): void
    {
        $queue = $this->viewModel([], [
            'time' => 1760958000, 'queued_at' => 1760954400, 'queued' => 250, 'approved' => 240, 'reverted' => 0,
            'refused' => 10,
        ])->getSuggestionQueue();

        self::assertSame([
            'time' => '2025-10-20 11:00',
            'summary' => '250 suggestion(s) queued at 2025-10-20 10:00; approved 240, reverted 0, refused 10 '
                . '(reasons are in the Magento log).',
        ], $queue);

        $legacy = $this->viewModel([], [
            'time' => 1760958000, 'queued_at' => null, 'queued' => 0, 'approved' => 0, 'reverted' => 2,
            'refused' => 0,
        ])->getSuggestionQueue();

        self::assertSame('approved 0, reverted 2, refused 0 (reasons are in the Magento log).', $legacy['summary']);
    }
}
