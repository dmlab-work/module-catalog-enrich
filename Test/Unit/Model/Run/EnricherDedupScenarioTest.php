<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Run;

use DmLab\CatalogEnrich\Model\Attempt;
use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Model\Run\RunOptions;
use DmLab\CatalogEnrich\Model\Run\RunReport;
use DmLab\CatalogEnrich\Model\Suggestion;
use DmLab\TypeSafeCore\Exception\RateLimitException;
use DmLab\TypeSafeCore\Exception\RequestException;
use DmLab\TypeSafeCore\Exception\TransportException;
use PHPUnit\Framework\Attributes\DataProvider;

class EnricherDedupScenarioTest extends EnricherTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->addSeats();
        $this->addAssembled();
        $this->addProduct(1);
        $this->addProduct(2);
        $this->addProduct(3);
        // 1 accepted, 2 not stated, 3 uncertain (orders disagree).
        $this->script[1] = ['number_of_seats' => '6', 'is_assembled' => 'Yes'];
        $this->script[3] = [
            'number_of_seats' => ['fwd' => '6', 'rev' => '4'],
            'is_assembled' => ['fwd' => 'Yes', 'rev' => 'No'],
        ];
    }

    public function testSecondIdenticalRunSendsZeroRequests(): void
    {
        $this->enrich();
        self::assertSame([1, 2, 3], $this->askedProductIds());
        self::assertSame(Attempt::OUTCOME_ACCEPTED, $this->attempts[1]['number_of_seats']['outcome']);
        self::assertSame(Attempt::OUTCOME_NOT_STATED, $this->attempts[2]['number_of_seats']['outcome']);
        self::assertSame(Attempt::OUTCOME_UNCERTAIN, $this->attempts[3]['number_of_seats']['outcome']);
        $writes = $this->calls['suggestionWrites'];

        $report = $this->enrich();

        self::assertCount(1, $this->batches);
        self::assertSame(0, $report->get(RunReport::REQUESTS));
        self::assertSame(0, $report->get(RunReport::ASKED));
        self::assertSame(6, $report->get(RunReport::SKIPPED_FINAL));
        self::assertSame($writes, $this->calls['suggestionWrites']);
        self::assertSame([], $report->getSuggestions());
        self::assertCount(2, $this->suggestions);
    }

    public function testEditedDescriptionReasksOnlyThatProduct(): void
    {
        $this->enrich();
        $this->products[2]['description'] = 'Now with four chairs.';

        $this->enrich();

        self::assertSame([2], array_keys($this->batches[1]));
        self::assertSame('Now with four chairs.', $this->batches[1][2]->getState()['description']);
    }

    public function testAddedOptionReasksAllProductsForThatAttribute(): void
    {
        $this->enrich();
        $this->descriptors['number_of_seats'] = new AttributeDescriptor(
            101,
            'number_of_seats',
            'Number of Seats',
            'select',
            't',
            [10 => '2', 11 => '4', 12 => '6', 13 => '8']
        );

        $this->enrich();

        self::assertSame([1, 2, 3], array_keys($this->batches[1]));
        foreach ($this->batches[1] as $request) {
            self::assertSame(['number_of_seats', 'number_of_seats:rev'], array_keys($request->getQuestions()));
        }
    }

    public function testEditedRuleTextReasks(): void
    {
        $this->enrich();
        $this->rules['is_assembled']['extra_rules'] = 'Flat-pack furniture is not assembled.';

        $this->enrich();

        self::assertSame([1, 2, 3], array_keys($this->batches[1]));
        self::assertSame(['is_assembled', 'is_assembled:rev'], array_keys($this->batches[1][1]->getQuestions()));

        $this->rules['is_assembled']['instructions'] = 'Is it delivered assembled?';
        $this->enrich();

        self::assertCount(3, $this->batches);
    }

    public function testChangedCoreModelReasks(): void
    {
        $this->enrich();
        $this->model = 'jev-2.0.0';

        $this->enrich();

        self::assertSame([1, 2, 3], array_keys($this->batches[1]));
        self::assertSame('jev-2.0.0', $this->batches[1][1]->getModel());
        self::assertSame(
            $this->expectedFingerprint(1, 'number_of_seats', 'jev-2.0.0'),
            $this->attempts[1]['number_of_seats']['fingerprint']
        );
    }

    public function testForceReasks(): void
    {
        $this->enrich();

        $this->enrich(null, new RunOptions(null, true));

        self::assertSame([1, 2, 3], array_keys($this->batches[1]));
        self::assertSame(2, $this->attempts[1]['number_of_seats']['attempts']);
        self::assertCount(2, $this->suggestions);
    }

    public function testErrorIsSkippedDuringBackoffAndAskedAfter(): void
    {
        $this->failures[2] = new TransportException(__('TypeSafe answered HTTP 503.'), null, 503);
        $this->enrich();
        self::assertSame(Attempt::OUTCOME_ERROR, $this->attempts[2]['number_of_seats']['outcome']);
        $this->failures = [];

        $report = $this->enrich();
        self::assertCount(1, $this->batches);
        self::assertSame(2, $report->get(RunReport::SKIPPED_BACKOFF));

        $this->now += 3600;
        $this->enrich();

        self::assertCount(2, $this->batches);
        self::assertSame([2], array_keys($this->batches[1]));
        self::assertSame(Attempt::OUTCOME_NOT_STATED, $this->attempts[2]['number_of_seats']['outcome']);
        self::assertNull($this->attempts[2]['number_of_seats']['next_attempt_at']);
    }

    public function testLoweredThresholdAcceptsStoredUncertainLocally(): void
    {
        $this->script[1]['number_of_seats'] = ['fwd' => '6', 'rev' => '6', 'p' => 0.85];
        $this->enrich();
        self::assertSame(Attempt::OUTCOME_UNCERTAIN, $this->attempts[1]['number_of_seats']['outcome']);
        self::assertSame([], $this->suggestionsFor(1, 'number_of_seats'));
        $this->rules['number_of_seats']['threshold'] = 0.8;

        $report = $this->enrich();

        self::assertCount(1, $this->batches);
        self::assertSame(Attempt::OUTCOME_ACCEPTED, $this->attempts[1]['number_of_seats']['outcome']);
        self::assertSame(1, $this->attempts[1]['number_of_seats']['attempts']);
        $suggestion = $this->suggestionsFor(1, 'number_of_seats')[0];
        self::assertSame('12', $suggestion['value']);
        self::assertSame(Suggestion::STATUS_PENDING, $suggestion['status']);
        self::assertSame([Suggestion::STATUS_PENDING => 1], $report->getSuggestions());
    }

    public function testRaisedThresholdDropsThePendingSuggestionLocally(): void
    {
        $this->script[1]['number_of_seats'] = ['fwd' => '6', 'rev' => '6', 'p' => 0.85];
        $this->rules['number_of_seats']['threshold'] = 0.8;
        $this->enrich();
        self::assertCount(1, $this->suggestionsFor(1, 'number_of_seats'));
        $this->rules['number_of_seats']['threshold'] = 0.95;

        $this->enrich();

        self::assertCount(1, $this->batches);
        self::assertSame(Attempt::OUTCOME_UNCERTAIN, $this->attempts[1]['number_of_seats']['outcome']);
        self::assertSame([], $this->suggestionsFor(1, 'number_of_seats'));
    }

    public function testCorruptStoredAnswersBecomeAnErrorWithBackoff(): void
    {
        $this->enrich();
        $this->attempts[1]['number_of_seats']['answer'] = '{"x":1}';

        $report = $this->enrich();

        self::assertCount(1, $this->batches);
        self::assertSame(Attempt::OUTCOME_ERROR, $this->attempts[1]['number_of_seats']['outcome']);
        self::assertNotNull($this->attempts[1]['number_of_seats']['next_attempt_at']);
        self::assertSame(6, $report->get(RunReport::SKIPPED_FINAL));
    }

    public function testModeShadowToReviewPromotesWithoutRequest(): void
    {
        $this->rules['number_of_seats']['mode'] = Rule::MODE_SHADOW;
        $this->enrich();
        self::assertSame(Suggestion::STATUS_SHADOW, $this->suggestionsFor(1, 'number_of_seats')[0]['status']);

        $this->rules['number_of_seats']['mode'] = Rule::MODE_REVIEW;
        $this->enrich();

        self::assertCount(1, $this->batches);
        $rows = $this->suggestionsFor(1, 'number_of_seats');
        self::assertCount(1, $rows);
        self::assertSame(Suggestion::STATUS_PENDING, $rows[0]['status']);
    }

    public function testRejectedSuggestionIsNotRecreated(): void
    {
        $this->enrich();
        $id = (int)$this->suggestionsFor(1, 'number_of_seats')[0]['suggestion_id'];
        $this->suggestions[$id]['status'] = Suggestion::STATUS_REJECTED;

        $this->enrich();
        $this->enrich(null, new RunOptions(null, true));

        $rows = $this->suggestionsFor(1, 'number_of_seats');
        self::assertCount(1, $rows);
        self::assertSame(Suggestion::STATUS_REJECTED, $rows[0]['status']);
    }

    public function testRejectedValueStaysBlockedAcrossFingerprintChanges(): void
    {
        $this->enrich();
        $id = (int)$this->suggestionsFor(1, 'number_of_seats')[0]['suggestion_id'];
        $this->suggestions[$id]['status'] = Suggestion::STATUS_REJECTED;
        $this->descriptors['number_of_seats'] = new AttributeDescriptor(
            101,
            'number_of_seats',
            'Number of Seats',
            'select',
            't',
            [10 => '2', 11 => '4', 12 => '6', 13 => '8']
        );

        $this->enrich();

        self::assertSame([1, 2, 3], array_keys($this->batches[1]));
        self::assertCount(1, $this->suggestionsFor(1, 'number_of_seats'));

        $this->model = 'jev-2.0.0';
        $this->script[1]['number_of_seats'] = '4';
        $this->enrich();

        $rows = $this->suggestionsFor(1, 'number_of_seats');
        self::assertCount(2, $rows);
        self::assertSame(['12', '11'], array_column($rows, 'value'));
        self::assertSame([Suggestion::STATUS_REJECTED, Suggestion::STATUS_PENDING], array_column($rows, 'status'));
    }

    public function testRevertedValueIsNotAutoAppliedAgainAfterAModelChange(): void
    {
        $this->rules['is_assembled']['mode'] = Rule::MODE_AUTO;
        $this->enrich();
        $id = (int)$this->suggestionsFor(1, 'is_assembled')[0]['suggestion_id'];
        self::assertSame(Suggestion::STATUS_APPLIED, $this->suggestions[$id]['status']);
        $this->suggestions[$id]['status'] = Suggestion::STATUS_REVERTED;

        $this->model = 'jev-2.0.0';
        $this->enrich();

        $rows = $this->suggestionsFor(1, 'is_assembled');
        self::assertCount(1, $rows);
        self::assertSame(Suggestion::STATUS_REVERTED, $rows[0]['status']);

        $this->model = 'jev-3.0.0';
        $this->script[1]['is_assembled'] = 'No';
        $this->enrich();

        $rows = $this->suggestionsFor(1, 'is_assembled');
        self::assertSame(['1', '0'], array_column($rows, 'value'));
        self::assertSame([Suggestion::STATUS_REVERTED, Suggestion::STATUS_APPLIED], array_column($rows, 'status'));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function refusedStatuses(): array
    {
        return ['not encodable' => [0], 'bad request' => [400], 'too large' => [413], 'unprocessable' => [422]];
    }

    #[DataProvider('refusedStatuses')]
    public function testRefusedDataIsNeverResentUntilItChanges(int $status): void
    {
        $this->failures[2] = new RequestException(__('Request too large'), null, $status);
        $report = $this->enrich();
        self::assertSame(Attempt::OUTCOME_REJECTED, $this->attempts[2]['number_of_seats']['outcome']);
        self::assertNull($this->attempts[2]['number_of_seats']['next_attempt_at']);
        self::assertSame(2, $report->get(RunReport::REJECTED));
        self::assertSame(0, $report->get(RunReport::ERRORS));
        $this->failures = [];

        $this->now += 30 * 86400;
        $report = $this->enrich();
        self::assertCount(1, $this->batches);
        self::assertSame(6, $report->get(RunReport::SKIPPED_FINAL));

        $this->products[2]['description'] = 'Shorter text.';
        $this->enrich();
        self::assertSame([2], array_keys($this->batches[1]));
        self::assertSame(Attempt::OUTCOME_NOT_STATED, $this->attempts[2]['number_of_seats']['outcome']);
    }

    public function testRefusedDataIsResentWithForce(): void
    {
        $this->failures[2] = new RequestException(__('Request too large'), null, 413);
        $this->enrich();
        $this->failures = [];

        $this->enrich(null, new RunOptions(null, true));

        self::assertSame([1, 2, 3], array_keys($this->batches[1]));
        self::assertSame(Attempt::OUTCOME_NOT_STATED, $this->attempts[2]['number_of_seats']['outcome']);
    }

    public function testOtherClientErrorsKeepTheBackoff(): void
    {
        $this->failures[2] = new RequestException(__('Not found'), null, 404);

        $report = $this->enrich();

        self::assertSame(Attempt::OUTCOME_ERROR, $this->attempts[2]['number_of_seats']['outcome']);
        self::assertSame(0, $report->get(RunReport::REJECTED));
    }

    public function testManuallyFilledValueDropsTheOpenSuggestion(): void
    {
        $this->enrich();
        self::assertCount(1, $this->suggestionsFor(1, 'number_of_seats'));
        $this->products[1]['number_of_seats'] = '11';

        $this->enrich();

        self::assertSame([], $this->suggestionsFor(1, 'number_of_seats'));
        self::assertContains(['number_of_seats', [1]], $this->calls['dropFilled']);
    }

    public function testDryRunDropsNothing(): void
    {
        $this->enrich();
        $this->products[1]['number_of_seats'] = '11';

        $this->enrich(null, new RunOptions(null, false, true));

        self::assertCount(1, $this->suggestionsFor(1, 'number_of_seats'));
    }

    public function testModeReviewToAutoAppliesThePendingSuggestion(): void
    {
        $this->enrich();
        self::assertSame(Suggestion::STATUS_PENDING, $this->suggestionsFor(1, 'number_of_seats')[0]['status']);

        $this->rules['number_of_seats']['mode'] = Rule::MODE_AUTO;
        $this->enrich();

        self::assertCount(1, $this->batches);
        $rows = $this->suggestionsFor(1, 'number_of_seats');
        self::assertCount(1, $rows);
        self::assertSame(Suggestion::STATUS_APPLIED, $rows[0]['status']);
    }

    public function testModeReviewToShadowMovesThePendingSuggestionToShadow(): void
    {
        $this->enrich();

        $this->rules['number_of_seats']['mode'] = Rule::MODE_SHADOW;
        $this->enrich();

        self::assertCount(1, $this->batches);
        self::assertSame(Suggestion::STATUS_SHADOW, $this->suggestionsFor(1, 'number_of_seats')[0]['status']);
    }

    public function testLocalRedecisionRecordsTheModelThatAnswered(): void
    {
        $this->script[1]['number_of_seats'] = ['fwd' => '6', 'rev' => '6', 'p' => 0.85];
        $this->enrich();
        $this->attempts[1]['number_of_seats']['model'] = 'jev-1.13.0-build7';
        $this->rules['number_of_seats']['threshold'] = 0.8;

        $this->enrich();

        self::assertCount(1, $this->batches);
        self::assertSame('jev-1.13.0-build7', $this->suggestionsFor(1, 'number_of_seats')[0]['model']);
    }

    public function testWholeChunkRateLimitedStopsWithoutBackoffRows(): void
    {
        foreach ([1, 2, 3] as $productId) {
            $this->failures[$productId] = new RateLimitException(__('TypeSafe rate limit reached.'), null, 429);
        }

        $report = $this->enrich();

        self::assertSame('TypeSafe rate limit reached.', $report->getStopReason());
        self::assertSame([], $this->attempts);
        self::assertSame(0, $report->get(RunReport::ERRORS));
    }

    public function testPartlyRateLimitedChunkBacksOffOnlyThoseProducts(): void
    {
        $this->failures[2] = new RateLimitException(__('TypeSafe rate limit reached.'), null, 429);

        $report = $this->enrich();

        self::assertNull($report->getStopReason());
        self::assertSame(Attempt::OUTCOME_ERROR, $this->attempts[2]['number_of_seats']['outcome']);
        self::assertSame(Attempt::OUTCOME_ACCEPTED, $this->attempts[1]['number_of_seats']['outcome']);
    }
}
