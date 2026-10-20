<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Run;

use DmLab\CatalogEnrich\Model\Attempt;
use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Model\Run\RunOptions;
use DmLab\CatalogEnrich\Model\Run\RunReport;
use DmLab\CatalogEnrich\Model\Suggestion;

/**
 * Which products and attributes a run looks at: configurables, visibility, store-view values, boolean 0, limits.
 */
class EnricherScopeTest extends EnricherTestCase
{
    public function testConfigurableParentSuperAttributeIsNeverAsked(): void
    {
        $this->addSeats();
        $this->addAssembled();
        $this->addProduct(1, ['type_id' => 'configurable']);
        $this->addProduct(2);
        $this->superAttributes[1] = [101];

        $this->enrich();

        self::assertSame(['is_assembled', 'is_assembled:rev'], array_keys($this->batches[0][1]->getQuestions()));
        self::assertContains('number_of_seats', array_keys($this->batches[0][2]->getQuestions()));
        self::assertSame(1, $this->calls['superReads']);
    }

    public function testSuperAttributesAreNotQueriedWithoutConfigurables(): void
    {
        $this->addSeats();
        $this->addProduct(1);
        $this->superAttributes[1] = [101];

        $this->enrich();

        self::assertSame(0, $this->calls['superReads']);
        self::assertSame([1], $this->askedProductIds());
    }

    public function testNotVisibleIndividuallyIsSkippedUnlessConfigured(): void
    {
        $this->addSeats();
        $this->addProduct(1);
        $this->addProduct(2, ['visibility' => '1']);

        $report = $this->enrich();

        self::assertSame([1], $this->askedProductIds());
        self::assertSame(1, $report->get(RunReport::PRODUCTS));

        $this->includeNotVisible = true;
        $this->enrich();

        self::assertSame([2], array_keys($this->batches[1]));
    }

    public function testStoreViewValueCountsAsFilled(): void
    {
        $this->addSeats();
        $this->addProduct(1);
        $this->addProduct(2);
        $this->storeViewValues[1]['number_of_seats'] = ['11'];
        $this->script[2] = ['number_of_seats' => '6'];

        $this->enrich();

        self::assertSame([2], $this->askedProductIds());
        self::assertArrayNotHasKey('dropFilled', $this->calls);
        self::assertCount(1, $this->suggestionsFor(2, 'number_of_seats'));

        $this->storeViewValues[2]['number_of_seats'] = ['10'];
        $this->enrich();

        self::assertSame([['number_of_seats', [2]]], $this->calls['dropFilled']);
        self::assertSame([], $this->suggestionsFor(2, 'number_of_seats'));
    }

    public function testStoreViewValueAlsoSkipsAddMissing(): void
    {
        $this->addSuite(['fill_mode' => Rule::FILL_ADD_MISSING]);
        $this->addProduct(1);
        $this->addProduct(2, ['bedroom_suite_incl' => '412']);
        $this->storeViewValues[1]['bedroom_suite_incl'] = ['413'];
        $this->storeViewValues[2]['bedroom_suite_incl'] = ['413'];
        $this->addProduct(3, ['bedroom_suite_incl' => '412']);

        $this->enrich();

        // A store-0 union would stay hidden behind the store-view value.
        self::assertSame([3], $this->askedProductIds());
    }

    public function testZeroIsAValueWithoutTheRuleFlag(): void
    {
        $this->addAssembled();
        $this->addProduct(1, ['is_assembled' => '0']);
        $this->addProduct(2);
        $this->storeViewValues[2]['is_assembled'] = ['0'];

        $this->enrich();

        self::assertSame([], $this->askedProductIds());
    }

    public function testTreatZeroAsEmptyAsksOverAZero(): void
    {
        $this->addAssembled(['treat_zero_as_empty' => 1]);
        $this->addProduct(1, ['is_assembled' => '0']);
        $this->addProduct(2);
        $this->addProduct(3, ['is_assembled' => '1']);
        $this->storeViewValues[2]['is_assembled'] = ['0'];
        $this->script[1] = ['is_assembled' => 'Yes'];

        $this->enrich();

        // The flag applies to store 0 only: a store-view 0 is a value.
        self::assertSame([1], $this->askedProductIds());
        $row = $this->suggestionsFor(1, 'is_assembled')[0];
        self::assertSame('1', $row['value']);
        self::assertNull($row['previous_value']);
    }

    public function testNoOverAZeroTreatedAsEmptyIsNotSuggested(): void
    {
        $this->addAssembled(['treat_zero_as_empty' => 1]);
        $this->addProduct(1, ['is_assembled' => '0']);
        $this->script[1] = ['is_assembled' => 'No'];

        $report = $this->enrich();

        self::assertSame([1], $this->askedProductIds());
        self::assertSame([], $this->suggestionsFor(1, 'is_assembled'));
        self::assertSame(Attempt::OUTCOME_NOT_STATED, $this->attempts[1]['is_assembled']['outcome']);
        self::assertSame(1, $report->get(RunReport::NOT_STATED));
        self::assertSame(0, $report->get(RunReport::ACCEPTED));
    }

    public function testStoredNoReDecidesAnOpenNoSuggestionAway(): void
    {
        $this->addAssembled(['treat_zero_as_empty' => 1]);
        $this->addProduct(1);
        $this->script[1] = ['is_assembled' => 'No'];
        $this->enrich();
        self::assertSame('0', $this->suggestionsFor(1, 'is_assembled')[0]['value']);

        $this->products[1]['is_assembled'] = '0';
        $this->enrich();

        self::assertCount(1, $this->batches);
        self::assertSame([], $this->suggestionsFor(1, 'is_assembled'));
        self::assertSame(Attempt::OUTCOME_NOT_STATED, $this->attempts[1]['is_assembled']['outcome']);
    }

    public function testProductLimitCountsOnlyProductsThatAsk(): void
    {
        $this->addSeats();
        foreach ([1, 2, 3, 4, 5] as $id) {
            $this->addProduct($id);
        }
        $this->enrich([1, 2]);

        $report = $this->enrich(null, new RunOptions(productLimit: 2));

        self::assertSame([3, 4], array_keys($this->batches[1]));
        self::assertTrue($report->isLimitReached());
        self::assertSame(4, $report->getLastProductId());
        self::assertSame(2, $report->getAskedProducts());
        self::assertSame(2, $report->get(RunReport::SKIPPED_FINAL));
    }

    public function testProductLimitAcrossChunksAndInDryRun(): void
    {
        $this->addSeats();
        foreach (range(1, 60) as $id) {
            $this->addProduct($id);
        }

        $report = $this->enrich(null, new RunOptions(null, false, true, RunOptions::DEFAULT_CONSUMER, 55));

        self::assertCount(55, $report->getLines());
        self::assertSame(55, $report->getLastProductId());
        self::assertTrue($report->isLimitReached());

        $report = $this->enrich(null, new RunOptions(productLimit: 60));
        self::assertFalse($report->isLimitReached());
        self::assertSame(60, $report->getLastProductId());
    }

    public function testUnchangedSecondRunCostIsConstantInProductCount(): void
    {
        $small = $this->secondRunCalls(5);
        $large = $this->secondRunCalls(45);

        self::assertSame($small, $large);
        self::assertSame(1, $large['loads']);
        self::assertSame(1, $large['ledgerReads']);
        self::assertSame(1, $large['suggestionReads']);
        self::assertSame(0, $large['ledgerWrites']);
        self::assertSame(0, $large['suggestionWrites']);
        self::assertSame(0, $large['suggestionDeletes']);
    }

    /**
     * Collaborator calls of the second of two identical runs over one chunk of `$count` products.
     *
     * Mixes accepted (review, auto, shadow), not stated, uncertain, filled and store-view filled pairs.
     *
     * @param int $count
     * @return array<string,int>
     */
    private function secondRunCalls(int $count): array
    {
        $this->products = $this->suggestions = $this->attempts = $this->script = $this->storeViewValues = [];
        $this->rules = $this->descriptors = $this->batches = [];
        $this->addSeats();
        $this->addAssembled(['mode' => Rule::MODE_AUTO]);
        $this->addSuite(['mode' => Rule::MODE_SHADOW]);
        for ($id = 1; $id <= $count; $id++) {
            $this->addProduct($id, $id % 4 === 0 ? ['number_of_seats' => '11'] : []);
            if ($id % 5 === 0) {
                $this->storeViewValues[$id]['bedroom_suite_incl'] = ['412'];
            }
            $this->script[$id] = match ($id % 3) {
                0 => [
                    'number_of_seats' => '6',
                    'is_assembled' => 'Yes',
                    'bedroom_suite_incl' => ['noul' => [412 => 0.97]],
                ],
                1 => ['number_of_seats' => ['fwd' => '6', 'rev' => '4'], 'is_assembled' => 'No'],
                default => [],
            };
        }
        $this->enrich();
        self::assertNotSame([], array_filter(
            $this->suggestions,
            static fn (array $row): bool => $row['status'] === Suggestion::STATUS_APPLIED
        ));
        $this->calls = array_fill_keys(array_keys(array_filter($this->calls, 'is_int')), 0);

        $report = $this->enrich();

        self::assertSame(0, $report->get(RunReport::REQUESTS));
        unset($this->calls['dropFilled']);

        return $this->calls;
    }
}
