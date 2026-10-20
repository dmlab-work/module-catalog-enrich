<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Run;

use DmLab\CatalogEnrich\Model\Attempt;
use DmLab\CatalogEnrich\Model\Run\RunOptions;
use DmLab\CatalogEnrich\Model\Run\RunReport;
use DmLab\TypeSafeCore\Exception\AuthenticationException;
use DmLab\TypeSafeCore\Exception\BudgetExceededException;
use DmLab\TypeSafeCore\Exception\CircuitOpenException;
use DmLab\TypeSafeCore\Exception\NotConfiguredException;
use DmLab\TypeSafeCore\Exception\RateLimitException;
use DmLab\TypeSafeCore\Exception\RequestException;
use DmLab\TypeSafeCore\Exception\TransportException;
use DmLab\TypeSafeCore\Exception\TypeSafeException;
use PHPUnit\Framework\Attributes\DataProvider;

class EnricherFailureSafetyTest extends EnricherTestCase
{
    /**
     * @return array<string, array{0: TypeSafeException}>
     */
    public static function perProductFailures(): array
    {
        return [
            'request' => [new RequestException(__('Bad request'), null, 404)],
            'transport' => [new TransportException(__('Bad request'))],
            'rate limit' => [new RateLimitException(__('Bad request'), null, 0, 30)],
        ];
    }

    #[DataProvider('perProductFailures')]
    public function testPerProductFailureRecordsErrorsAndContinues(TypeSafeException $failure): void
    {
        $this->addSeats();
        $this->addAssembled();
        foreach ([1, 2, 3] as $id) {
            $this->addProduct($id);
            $this->script[$id] = ['number_of_seats' => '4'];
        }
        $this->failures[2] = $failure;

        $report = $this->enrich();

        foreach (['number_of_seats', 'is_assembled'] as $code) {
            self::assertSame(Attempt::OUTCOME_ERROR, $this->attempts[2][$code]['outcome']);
            self::assertSame('Bad request', $this->attempts[2][$code]['error_message']);
            self::assertNotNull($this->attempts[2][$code]['next_attempt_at']);
        }
        self::assertSame(Attempt::OUTCOME_ACCEPTED, $this->attempts[1]['number_of_seats']['outcome']);
        self::assertSame(Attempt::OUTCOME_ACCEPTED, $this->attempts[3]['number_of_seats']['outcome']);
        self::assertSame(2, $report->get(RunReport::ERRORS));
        self::assertSame(2, $report->get(RunReport::ACCEPTED));
        self::assertNull($report->getStopReason());
    }

    /**
     * @return array<string, array{0: TypeSafeException}>
     */
    public static function stopFailures(): array
    {
        return [
            'budget' => [new BudgetExceededException(__('Monthly budget exceeded'))],
            'circuit' => [new CircuitOpenException(__('Monthly budget exceeded'))],
            'not configured' => [new NotConfiguredException(__('Monthly budget exceeded'))],
            'authentication' => [new AuthenticationException(__('Monthly budget exceeded'))],
        ];
    }

    #[DataProvider('stopFailures')]
    public function testStopFailureFinishesTheChunkAndStops(TypeSafeException $failure): void
    {
        $this->addSeats();
        for ($id = 1; $id <= 60; $id++) {
            $this->addProduct($id);
            $this->script[$id] = ['number_of_seats' => '6'];
        }
        $this->failures[2] = $failure;

        $report = $this->enrich();

        self::assertCount(1, $this->batches);
        self::assertSame('Monthly budget exceeded', $report->getStopReason());
        self::assertArrayNotHasKey(2, $this->attempts);
        self::assertCount(49, $this->attempts);
        self::assertSame(Attempt::OUTCOME_ACCEPTED, $this->attempts[1]['number_of_seats']['outcome']);
        self::assertArrayNotHasKey(51, $this->attempts);
        self::assertSame(0, $report->get(RunReport::ERRORS));
        self::assertSame(49, $report->get(RunReport::ACCEPTED));
    }

    public function testBatchLevelStopFailure(): void
    {
        $this->addSeats();
        $this->addProduct(1);
        $this->batchFailure = new BudgetExceededException(__('Monthly budget exceeded'));

        $report = $this->enrich();

        self::assertSame('Monthly budget exceeded', $report->getStopReason());
        self::assertSame([], $this->attempts);
    }

    public function testBatchLevelPerProductFailure(): void
    {
        $this->addSeats();
        $this->addProduct(1);
        $this->addProduct(2);
        $this->batchFailure = new TransportException(__('Connection reset'));

        $report = $this->enrich();

        self::assertNull($report->getStopReason());
        self::assertSame(Attempt::OUTCOME_ERROR, $this->attempts[1]['number_of_seats']['outcome']);
        self::assertSame(Attempt::OUTCOME_ERROR, $this->attempts[2]['number_of_seats']['outcome']);
        self::assertSame(2, $report->get(RunReport::ERRORS));
    }

    public function testMissingAnswerRecordsAnErrorForThatAttributeOnly(): void
    {
        $this->addSeats();
        $this->addSuite();
        $this->addProduct(1);
        $this->script[1] = ['number_of_seats' => '2'];
        $this->dropAnswers[1] = ['bedroom_suite_incl:413'];

        $report = $this->enrich();

        self::assertSame(Attempt::OUTCOME_ACCEPTED, $this->attempts[1]['number_of_seats']['outcome']);
        self::assertSame(Attempt::OUTCOME_ERROR, $this->attempts[1]['bedroom_suite_incl']['outcome']);
        self::assertSame(
            'No answer for question "bedroom_suite_incl:413".',
            $this->attempts[1]['bedroom_suite_incl']['error_message']
        );
        self::assertSame(1, $report->get(RunReport::ERRORS));
        self::assertSame(1, $report->get(RunReport::ACCEPTED));
    }

    public function testDryRunMakesNoClientCallAndNoWrites(): void
    {
        $this->addSeats();
        $this->addAssembled();
        $this->addProduct(1);
        $this->addProduct(2, ['is_assembled' => '1']);
        // Nothing to ask or skip: no dry-run line.
        $this->addProduct(3, ['number_of_seats' => '10', 'is_assembled' => '0']);
        $this->attempts[2]['number_of_seats'] = [
            'product_id' => 2,
            'attribute_code' => 'number_of_seats',
            'fingerprint' => $this->expectedFingerprint(2, 'number_of_seats'),
            'outcome' => Attempt::OUTCOME_ACCEPTED,
            'answer' => (string)json_encode([
                'number_of_seats' => ['type' => 'choice', 'choice' => '2', 'probabilities' => ['2' => 0.99]],
                'number_of_seats:rev' => ['type' => 'choice', 'choice' => '2', 'probabilities' => ['2' => 0.99]],
            ]),
            'attempts' => 1,
        ];

        $report = $this->enrich(null, new RunOptions(null, false, true));

        self::assertSame([], $this->batches);
        self::assertSame(0, $this->calls['ledgerWrites']);
        self::assertSame(0, $this->calls['suggestionWrites']);
        self::assertSame(
            [
                'SKU-1: asked [number_of_seats, is_assembled] skipped []',
                'SKU-2: asked [] skipped [number_of_seats]',
            ],
            $report->getLines()
        );
        self::assertSame(2, $report->get(RunReport::ASKED));
        self::assertSame(1, $report->get(RunReport::SKIPPED_FINAL));
        self::assertSame(0, $report->get(RunReport::REQUESTS));
    }
}
