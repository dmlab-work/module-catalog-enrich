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
use DmLab\TypeSafeCore\Api\Data\Question\ChoiceQuestion;
use DmLab\TypeSafeCore\Api\Data\Question\NoulQuestion;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;

class EnricherTest extends EnricherTestCase
{
    public function testRequestShape(): void
    {
        $this->model = 'jev-9.9.9';
        $this->addSeats(['extra_rules' => "A dining setting of N pieces is one table plus N-1 seats."]);
        $this->addAssembled();
        $this->addSuite(['fill_mode' => Rule::FILL_ADD_MISSING]);
        $this->addProduct(7, [
            'name' => 'Oslo 7 Piece Dining Suite',
            'short_description' => '<p>Solid&nbsp;oak</p>',
            'description' => 'Table with six chairs.',
        ]);

        $this->enrich();

        self::assertCount(1, $this->batches);
        self::assertSame([7], array_keys($this->batches[0]));
        $request = $this->batches[0][7];
        self::assertSame(
            [
                'name' => 'Oslo 7 Piece Dining Suite',
                'short_description' => 'Solid oak',
                'description' => 'Table with six chairs.',
            ],
            $request->getState()
        );
        self::assertSame('catalog_enrich', $request->getConsumer());
        self::assertSame('jev-9.9.9', $request->getModel());

        $questions = $request->getQuestions();
        self::assertSame(
            [
                'number_of_seats', 'number_of_seats:rev', 'is_assembled', 'is_assembled:rev',
                'bedroom_suite_incl:412', 'bedroom_suite_incl:413',
            ],
            array_map('strval', array_keys($questions))
        );
        self::assertInstanceOf(ChoiceQuestion::class, $questions['number_of_seats']);
        self::assertSame(['2', '4', '6', 'not stated'], $this->criteriaKeys($questions['number_of_seats']));
        self::assertSame(['6', '4', '2', 'not stated'], $this->criteriaKeys($questions['number_of_seats:rev']));
        self::assertSame(
            [
                'question' => 'What is the number of seats of this product?',
                'rules' => ['A dining setting of N pieces is one table plus N-1 seats.'],
            ],
            $questions['number_of_seats']->toArray()['instructions']
        );
        self::assertSame(['Yes', 'No', 'not stated'], $this->criteriaKeys($questions['is_assembled']));
        self::assertSame(['No', 'Yes', 'not stated'], $this->criteriaKeys($questions['is_assembled:rev']));
        self::assertInstanceOf(NoulQuestion::class, $questions['bedroom_suite_incl:412']);

        foreach (['number_of_seats', 'is_assembled', 'bedroom_suite_incl'] as $code) {
            self::assertSame(
                $this->expectedFingerprint(7, $code, 'jev-9.9.9'),
                $this->attempts[7][$code]['fingerprint'],
                $code
            );
        }
    }

    public function testCustomConsumer(): void
    {
        $this->addSeats();
        $this->addProduct(1);

        $this->enrich(null, new RunOptions(null, false, false, 'catalog_enrich_calibrate'));

        self::assertSame('catalog_enrich_calibrate', $this->sentRequests()[0]->getConsumer());
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function dryRunFlags(): array
    {
        return ['run' => [false], 'dry run' => [true]];
    }

    #[DataProvider('dryRunFlags')]
    public function testNotConfiguredStopsBeforeAnyWork(bool $dryRun): void
    {
        $this->configured = false;
        $this->addSeats();
        $this->addProduct(1);

        $report = $this->enrich(null, new RunOptions(null, false, $dryRun));

        self::assertSame(
            'TypeSafe is not configured: enable it and set the API key under '
            . 'Stores > Configuration > DMLab > TypeSafe.',
            $report->getStopReason()
        );
        self::assertSame([], $this->batches);
        self::assertSame([0], array_values(array_unique($this->calls)));
        self::assertSame([], $report->getLines());
    }

    public function testOneRequestPerProductWithAllAttributes(): void
    {
        $this->addSeats();
        $this->addAssembled();
        foreach ([1, 2, 3] as $id) {
            $this->addProduct($id);
        }

        $report = $this->enrich();

        self::assertCount(1, $this->batches);
        self::assertSame([1, 2, 3], array_keys($this->batches[0]));
        foreach ($this->batches[0] as $request) {
            self::assertCount(4, $request->getQuestions());
        }
        self::assertSame(3, $report->get(RunReport::REQUESTS));
        self::assertSame(3, $report->get(RunReport::PRODUCTS));
        self::assertSame(6, $report->get(RunReport::ASKED));
        self::assertSame(3 * 770, $report->get(RunReport::INPUT_TOKENS));
    }

    public function testChunksOfFifty(): void
    {
        $this->addSeats();
        for ($id = 1; $id <= 120; $id++) {
            $this->addProduct($id);
        }

        $this->enrich();

        self::assertSame([50, 50, 20], array_map('count', $this->batches));
    }

    public function testFilledSelectUnderEmptyOnlyIsNotAsked(): void
    {
        $this->addSeats();
        $this->addAssembled();
        $this->addSuite(['fill_mode' => Rule::FILL_ADD_MISSING]);
        $this->addProduct(1, ['number_of_seats' => '12', 'is_assembled' => '0', 'bedroom_suite_incl' => '412']);
        $this->addProduct(2, ['number_of_seats' => '12', 'is_assembled' => '1', 'bedroom_suite_incl' => '']);

        $this->enrich();

        self::assertSame(
            ['bedroom_suite_incl:412', 'bedroom_suite_incl:413'],
            array_keys($this->batches[0][1]->getQuestions())
        );
        self::assertSame([1, 2], array_keys($this->batches[0]));
    }

    public function testAttributeSetFilter(): void
    {
        $this->setsForAttribute['number_of_seats'] = [4, 9];
        $this->addSeats(['attribute_set_ids' => '9,15']);
        $this->addAssembled();
        $this->addProduct(1);
        $this->addProduct(2, ['attribute_set_id' => '9']);
        $this->addProduct(3, ['attribute_set_id' => '15']);

        $this->enrich();

        $asked = array_map(
            static fn ($request): array => array_map('strval', array_keys($request->getQuestions())),
            $this->batches[0]
        );
        self::assertSame(
            [1 => ['is_assembled', 'is_assembled:rev'], 2 => ['number_of_seats', 'number_of_seats:rev']],
            $asked
        );
    }

    public function testUnusableAndMissingProductsAreSkipped(): void
    {
        $this->addSeats();
        $this->addProduct(1);
        $this->addProduct(2, ['name' => '  ', 'description' => '<p></p>', 'short_description' => 'Short only']);

        $report = $this->enrich([1, 2, 3]);

        self::assertSame([1], $this->askedProductIds());
        self::assertSame(1, $report->get(RunReport::PRODUCTS));
    }

    public function testNoActiveRulesSendsNothing(): void
    {
        $this->addSeats(['mode' => Rule::MODE_OFF]);
        $this->addProduct(1);

        $report = $this->enrich();

        self::assertSame([], $this->batches);
        self::assertSame(0, $this->calls['loads']);
        self::assertNull($report->getStopReason());
    }

    public function testSingleAttributeOption(): void
    {
        $this->addSeats();
        $this->addAssembled();
        $this->addProduct(1);

        $this->enrich(null, new RunOptions('is_assembled'));

        self::assertSame(['is_assembled', 'is_assembled:rev'], array_keys($this->sentRequests()[0]->getQuestions()));
    }

    public function testSingleAttributeWithoutActiveRuleThrows(): void
    {
        $this->addSeats(['mode' => Rule::MODE_OFF]);
        $this->addProduct(1);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('There is no active enrichment rule for attribute "number_of_seats".');

        $this->enrich(null, new RunOptions('number_of_seats'));
    }

    public function testOutcomesAndSuggestionsAreRecorded(): void
    {
        $this->addSeats();
        $this->addAssembled(['mode' => Rule::MODE_AUTO]);
        $this->addSuite(['mode' => Rule::MODE_SHADOW]);
        $this->addProduct(1);
        $this->addProduct(2);
        $this->script[1] = [
            'number_of_seats' => '6',
            'is_assembled' => 'No',
            'bedroom_suite_incl' => ['noul' => [412 => 0.97, 413 => 0.95]],
        ];
        $this->script[2] = [
            'number_of_seats' => ['fwd' => '6', 'rev' => '4'],
            'bedroom_suite_incl' => ['noul' => [412 => 0.5]],
        ];

        $report = $this->enrich();

        self::assertSame(Attempt::OUTCOME_ACCEPTED, $this->attempts[1]['number_of_seats']['outcome']);
        self::assertSame(Attempt::OUTCOME_UNCERTAIN, $this->attempts[2]['number_of_seats']['outcome']);
        self::assertSame(Attempt::OUTCOME_NOT_STATED, $this->attempts[2]['is_assembled']['outcome']);
        self::assertSame(Attempt::OUTCOME_UNCERTAIN, $this->attempts[2]['bedroom_suite_incl']['outcome']);
        self::assertSame('jev-1.13.0', $this->attempts[1]['number_of_seats']['model']);
        self::assertSame(3, $report->get(RunReport::ACCEPTED));
        self::assertSame(1, $report->get(RunReport::NOT_STATED));
        self::assertSame(2, $report->get(RunReport::UNCERTAIN));
        self::assertSame(0, $report->get(RunReport::ERRORS));

        self::assertSame('12', $this->suggestionsFor(1, 'number_of_seats')[0]['value']);
        self::assertSame(Suggestion::STATUS_PENDING, $this->suggestionsFor(1, 'number_of_seats')[0]['status']);
        self::assertSame('0', $this->suggestionsFor(1, 'is_assembled')[0]['value']);
        self::assertSame(Suggestion::STATUS_APPLIED, $this->suggestionsFor(1, 'is_assembled')[0]['status']);
        self::assertSame('412,413', $this->suggestionsFor(1, 'bedroom_suite_incl')[0]['value']);
        self::assertSame(Suggestion::STATUS_SHADOW, $this->suggestionsFor(1, 'bedroom_suite_incl')[0]['status']);
        self::assertSame([], $this->suggestionsFor(2, 'number_of_seats'));
        self::assertSame(
            [Suggestion::STATUS_PENDING => 1, Suggestion::STATUS_APPLIED => 1, Suggestion::STATUS_SHADOW => 1],
            $report->getSuggestions()
        );
    }

    public function testDuplicateAndInvalidIdsAreIgnored(): void
    {
        $this->addSeats();
        $this->addProduct(1);

        $this->enrich([1, '1', 0, -5]);

        self::assertSame([1], $this->askedProductIds());
    }

    public function testRuleWhoseQuestionsCannotBeBuiltIsSkippedAndOthersStillAsked(): void
    {
        $this->addSeats();
        $this->addSuite(['instructions' => 'Is this a bedroom suite?']);
        $this->addProduct(1);

        $report = $this->enrich();

        self::assertSame(['number_of_seats', 'number_of_seats:rev'], array_keys($this->batches[0][1]->getQuestions()));
        self::assertSame(1, $report->get(RunReport::ASKED));
    }

    public function testCachedResponsesAreCounted(): void
    {
        $this->addSeats();
        $this->addProduct(1);
        $this->addProduct(2);
        $this->cached = [2];

        $report = $this->enrich();

        self::assertSame(2, $report->get(RunReport::REQUESTS));
        self::assertSame(1, $report->get(RunReport::CACHED));
    }

    /**
     * @param ChoiceQuestion $question
     * @return string[]
     */
    private function criteriaKeys(ChoiceQuestion $question): array
    {
        return array_map('strval', array_keys((array)$question->toArray()['criteria']));
    }
}
