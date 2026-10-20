<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Dedup;

use DmLab\CatalogEnrich\Model\Decision\Decision;
use DmLab\CatalogEnrich\Model\Dedup\AttemptLedger;
use DmLab\CatalogEnrich\Model\Dedup\LedgerVerdict;
use DmLab\CatalogEnrich\Model\ResourceModel\Attempt as AttemptResource;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttemptLedgerTest extends TestCase
{
    private const FP = 'fp-1';
    private const ANSWERS = [
        'number_of_seats' => [
            'type' => 'choice',
            'choice' => '6',
            'probabilities' => ['6' => 0.96, 'not stated' => 0.04],
        ],
    ];

    /**
     * In-memory table: product id => code => row.
     *
     * @var array<int, array<string, array<string,mixed>>>
     */
    private array $table = [];

    /**
     * @var int
     */
    private int $now;

    /**
     * @var int
     */
    private int $loads = 0;

    /**
     * @var AttemptLedger
     */
    private AttemptLedger $ledger;

    protected function setUp(): void
    {
        $this->now = gmmktime(10, 0, 0, 10, 19, 2026);
        $this->ledger = $this->newLedger();
    }

    public function testNoRowAsks(): void
    {
        self::assertSame(LedgerVerdict::ASK, $this->ledger->verdict(5, 'color', self::FP, false)->getType());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function finalOutcomes(): array
    {
        return ['accepted' => ['accepted'], 'not_stated' => ['not_stated'], 'uncertain' => ['uncertain']];
    }

    #[DataProvider('finalOutcomes')]
    public function testSameFingerprintFinalSkipsWithStoredAnswers(string $outcome): void
    {
        $this->ledger->recordDecision(5, 'number_of_seats', self::FP, new Decision($outcome), self::ANSWERS, 'jev-1');

        $verdict = $this->newLedger()->verdict(5, 'number_of_seats', self::FP, false);

        self::assertSame(LedgerVerdict::SKIP_FINAL, $verdict->getType());
        self::assertFalse($verdict->isAsk());
        self::assertSame($outcome, $verdict->getOutcome());
        self::assertEquals(self::ANSWERS, $verdict->getAnswers());
    }

    #[DataProvider('finalOutcomes')]
    public function testChangedFingerprintAsksAndResetsAttempts(string $outcome): void
    {
        $this->ledger->recordDecision(5, 'color', self::FP, new Decision($outcome), self::ANSWERS, 'jev-1');
        $this->ledger->recordDecision(5, 'color', self::FP, new Decision($outcome), self::ANSWERS, 'jev-1');
        self::assertSame(2, $this->table[5]['color']['attempts']);

        $ledger = $this->newLedger();
        self::assertTrue($ledger->verdict(5, 'color', 'fp-2', false)->isAsk());

        $ledger->recordDecision(5, 'color', 'fp-2', new Decision($outcome), self::ANSWERS, 'jev-1');
        self::assertSame(1, $this->table[5]['color']['attempts']);
        self::assertSame('fp-2', $this->table[5]['color']['fingerprint']);
    }

    #[DataProvider('finalOutcomes')]
    public function testForceAsksForFinalOutcomes(string $outcome): void
    {
        $this->ledger->recordDecision(5, 'color', self::FP, new Decision($outcome), self::ANSWERS, 'jev-1');

        self::assertTrue($this->newLedger()->verdict(5, 'color', self::FP, true)->isAsk());
    }

    public function testErrorBackoffWindow(): void
    {
        $this->ledger->recordError(5, 'color', self::FP, 'timeout');

        self::assertSame(LedgerVerdict::SKIP_BACKOFF, $this->verdictType(5, 'color', self::FP));
        self::assertTrue($this->newLedger()->verdict(5, 'color', self::FP, true)->isAsk());

        $this->now += 3599;
        self::assertSame(LedgerVerdict::SKIP_BACKOFF, $this->verdictType(5, 'color', self::FP));
        $this->now += 1;
        self::assertTrue($this->newLedger()->verdict(5, 'color', self::FP, false)->isAsk());
    }

    public function testErrorWithChangedFingerprintAsksImmediately(): void
    {
        $this->ledger->recordError(5, 'color', self::FP, 'timeout');

        self::assertTrue($this->newLedger()->verdict(5, 'color', 'fp-2', false)->isAsk());
    }

    public function testBackoffDoublesAndIsCappedAtSevenDays(): void
    {
        $delays = [];
        for ($i = 0; $i < 12; $i++) {
            $this->ledger->recordError(5, 'color', self::FP, 'timeout');
            $row = $this->table[5]['color'];
            self::assertSame($i + 1, $row['attempts']);
            self::assertSame(gmdate('Y-m-d H:i:s', $this->now), $row['attempted_at']);
            $delays[] = strtotime($row['next_attempt_at'] . ' UTC') - $this->now;
        }

        self::assertSame(
            [3600, 7200, 14400, 28800, 57600, 115200, 230400, 460800, 604800, 604800, 604800, 604800],
            $delays
        );
    }

    public function testErrorRowShape(): void
    {
        $this->ledger->recordError(5, 'color', self::FP, str_repeat('é', 300));

        $row = $this->table[5]['color'];
        self::assertSame('error', $row['outcome']);
        self::assertNull($row['answer']);
        self::assertSame(255, mb_strlen($row['error_message']));
        self::assertSame(self::FP, $row['fingerprint']);
    }

    public function testRejectedIsSkippedWithoutTimeLimitUntilTheFingerprintChanges(): void
    {
        $this->ledger->recordRejected(5, 'color', self::FP, str_repeat('é', 300));
        $row = $this->table[5]['color'];
        self::assertSame('rejected', $row['outcome']);
        self::assertNull($row['answer']);
        self::assertNull($row['next_attempt_at']);
        self::assertSame(255, mb_strlen($row['error_message']));

        $this->now += 365 * 86400;
        $ledger = $this->newLedger();
        self::assertSame(LedgerVerdict::SKIP_REJECTED, $ledger->verdict(5, 'color', self::FP, false)->getType());
        self::assertSame(LedgerVerdict::ASK, $ledger->verdict(5, 'color', 'fp-2', false)->getType());
        self::assertSame(LedgerVerdict::ASK, $ledger->verdict(5, 'color', self::FP, true)->getType());
    }

    public function testFinalVerdictCarriesTheStoredModel(): void
    {
        $decision = new Decision('accepted', '12', 0.96);
        $this->ledger->recordDecision(5, 'color', self::FP, $decision, self::ANSWERS, 'jev-x');

        self::assertSame('jev-x', $this->newLedger()->verdict(5, 'color', self::FP, false)->getModel());
    }

    public function testErrorThenSuccessIsFinalAndClearsNextAttempt(): void
    {
        $this->ledger->recordError(5, 'color', self::FP, 'timeout');
        $this->now += 3600;
        $ledger = $this->newLedger();
        self::assertTrue($ledger->verdict(5, 'color', self::FP, false)->isAsk());

        $decision = new Decision('accepted', '12', 0.95);
        $ledger->recordDecision(5, 'color', self::FP, $decision, self::ANSWERS, 'jev-1.13.0');

        $row = $this->table[5]['color'];
        self::assertSame('accepted', $row['outcome']);
        self::assertNull($row['next_attempt_at']);
        self::assertNull($row['error_message']);
        self::assertSame(2, $row['attempts']);
        self::assertSame('jev-1.13.0', $row['model']);
        self::assertSame(LedgerVerdict::SKIP_FINAL, $this->verdictType(5, 'color', self::FP));
    }

    public function testRecordDecisionStoresNormalizedJson(): void
    {
        $answers = ['q' => ['type' => 'noul', 'p' => 1.0]];
        $this->ledger->recordDecision(5, 'color', self::FP, new Decision('uncertain'), $answers, 'm');

        self::assertSame('{"q":{"type":"noul","p":1.0}}', $this->table[5]['color']['answer']);
        self::assertSame(gmdate('Y-m-d H:i:s', $this->now), $this->table[5]['color']['attempted_at']);
    }

    public function testUpdateOutcomeKeepsAttemptsAndAttemptedAt(): void
    {
        $this->ledger->recordDecision(5, 'color', self::FP, new Decision('uncertain'), self::ANSWERS, 'jev-1');
        $this->ledger->recordDecision(5, 'color', self::FP, new Decision('uncertain'), self::ANSWERS, 'jev-1');
        $attemptedAt = $this->table[5]['color']['attempted_at'];
        $this->now += 86400;

        $this->newLedger()->updateOutcome(5, 'color', 'accepted');

        $row = $this->table[5]['color'];
        self::assertSame('accepted', $row['outcome']);
        self::assertSame(2, $row['attempts']);
        self::assertSame($attemptedAt, $row['attempted_at']);
        self::assertSame(self::ANSWERS, json_decode($row['answer'], true));
    }

    public function testUpdateOutcomeWithoutRowOrChangeWritesNothing(): void
    {
        $resource = $this->createMock(AttemptResource::class);
        $resource->method('loadForProducts')->willReturn([
            5 => ['color' => ['product_id' => '5', 'attribute_code' => 'color', 'fingerprint' => self::FP,
                'outcome' => 'uncertain', 'attempts' => '1']],
        ]);
        $resource->expects(self::never())->method('upsert');
        $ledger = new AttemptLedger($resource, $this->dateTime());

        $ledger->updateOutcome(9, 'color', 'accepted');
        $ledger->updateOutcome(5, 'color', 'uncertain');
    }

    public function testPrefetchLoadsOnceAndRemembersAbsentRows(): void
    {
        $this->table[5]['color'] = [
            'product_id' => '5', 'attribute_code' => 'color', 'fingerprint' => self::FP, 'outcome' => 'accepted',
            'answer' => json_encode(self::ANSWERS), 'attempts' => '1', 'next_attempt_at' => null,
        ];
        $this->ledger->prefetch([5, 6], ['color', 'style']);

        self::assertSame(LedgerVerdict::SKIP_FINAL, $this->ledger->verdict(5, 'color', self::FP, false)->getType());
        self::assertTrue($this->ledger->verdict(5, 'style', self::FP, false)->isAsk());
        self::assertTrue($this->ledger->verdict(6, 'color', self::FP, false)->isAsk());
        self::assertSame(1, $this->loads);

        $this->ledger->verdict(7, 'color', self::FP, false);
        self::assertSame(2, $this->loads);
    }

    public function testPrefetchDropsEarlierChunks(): void
    {
        $this->ledger->prefetch([5], ['color']);
        $this->ledger->prefetch([6], ['color']);
        self::assertSame(2, $this->loads);

        $this->ledger->verdict(5, 'color', self::FP, false);
        self::assertSame(3, $this->loads);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unusableAnswers(): array
    {
        return [
            'invalid json' => ['{broken'],
            'null' => [null],
            'empty string' => [''],
            'json scalar' => ['"x"'],
        ];
    }

    #[DataProvider('unusableAnswers')]
    public function testUnusableStoredAnswerAsks(mixed $answer): void
    {
        $this->table[5]['color'] = [
            'product_id' => '5', 'attribute_code' => 'color', 'fingerprint' => self::FP, 'outcome' => 'accepted',
            'answer' => $answer, 'attempts' => '1', 'next_attempt_at' => null,
        ];

        self::assertTrue($this->ledger->verdict(5, 'color', self::FP, false)->isAsk());
    }

    public function testUnknownVerdictTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LedgerVerdict('maybe');
    }

    private function verdictType(int $productId, string $code, string $fingerprint): string
    {
        return $this->newLedger()->verdict($productId, $code, $fingerprint, false)->getType();
    }

    private function newLedger(): AttemptLedger
    {
        $resource = $this->createStub(AttemptResource::class);
        $resource->method('loadForProducts')->willReturnCallback(function (array $ids, array $codes): array {
            $this->loads++;
            $result = [];
            foreach ($ids as $id) {
                foreach ($codes as $code) {
                    if (isset($this->table[$id][$code])) {
                        $result[$id][$code] = $this->table[$id][$code];
                    }
                }
            }

            return $result;
        });
        $resource->method('upsert')->willReturnCallback(function (array $row): void {
            $this->table[$row['product_id']][$row['attribute_code']] = $row;
        });

        return new AttemptLedger($resource, $this->dateTime());
    }

    private function dateTime(): DateTime
    {
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturnCallback(fn (): int => $this->now);

        return $dateTime;
    }
}
