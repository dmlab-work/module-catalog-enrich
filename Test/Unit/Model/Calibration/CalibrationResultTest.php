<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Calibration;

use DmLab\CatalogEnrich\Model\Calibration\CalibrationResult;
use PHPUnit\Framework\TestCase;

class CalibrationResultTest extends TestCase
{
    /**
     * Record `$count` select products at a threshold.
     */
    private function add(CalibrationResult $result, float $threshold, int $count, bool $decided, bool $correct): void
    {
        for ($i = 0; $i < $count; $i++) {
            $result->record($threshold, $decided, (int)$correct);
        }
    }

    public function testEmptyResultHasUndefinedRatios(): void
    {
        $result = new CalibrationResult('color', 'select', 0.9);

        self::assertSame(CalibrationResult::THRESHOLDS, array_column($result->getRows(), 'threshold'));
        foreach ($result->getRows() as $row) {
            self::assertSame(0, $row['decided']);
            self::assertNull($row['coverage']);
            self::assertNull($row['precision']);
            self::assertNull($row['recall']);
        }
        self::assertNull($result->suggestedThreshold());
        self::assertFalse($result->isMultiselect());
    }

    public function testSelectRatios(): void
    {
        $result = new CalibrationResult('color', 'select', 0.9);
        for ($i = 0; $i < 20; $i++) {
            $result->addProduct(100);
        }
        $this->add($result, 0.7, 19, true, true);
        $this->add($result, 0.7, 1, true, false);

        $row = $result->getRows()[1];

        self::assertSame(20, $row['decided']);
        self::assertSame(19, $row['correct']);
        self::assertEqualsWithDelta(1.0, $row['coverage'], 1e-9);
        self::assertEqualsWithDelta(0.95, $row['precision'], 1e-9);
        self::assertSame(2000, $result->getInputTokens());
        self::assertSame(0.7, $result->suggestedThreshold(), 'precision of exactly 0.95 qualifies');
    }

    public function testSuggestionIsTheLowestQualifyingThreshold(): void
    {
        $result = new CalibrationResult('color', 'select', 0.9);
        $this->add($result, 0.5, 20, true, true);
        $this->add($result, 0.5, 5, true, false);
        $this->add($result, 0.8, 12, true, true);
        $this->add($result, 0.9, 11, true, true);

        self::assertSame(0.8, $result->suggestedThreshold());
    }

    public function testHalfIsNeverSuggestedBecauseRulesRequireMore(): void
    {
        $result = new CalibrationResult('color', 'select', 0.9);
        $this->add($result, 0.5, 20, true, true);
        $this->add($result, 0.7, 12, true, true);

        self::assertSame(0.7, $result->suggestedThreshold());
    }

    public function testFewerThanTenDecidedNeverQualifies(): void
    {
        $result = new CalibrationResult('color', 'select', 0.9);
        foreach (CalibrationResult::THRESHOLDS as $threshold) {
            $this->add($result, $threshold, 9, true, true);
        }

        self::assertNull($result->suggestedThreshold());
    }

    public function testMultiselectPrecisionAndRecallArePerOption(): void
    {
        $result = new CalibrationResult('suite', 'multiselect', 0.9);
        $result->record(0.5, true, 3, 1, 1);
        $result->record(0.5, false, 0, 0, 2);

        $row = $result->getRows()[0];

        self::assertTrue($result->isMultiselect());
        self::assertSame(1, $row['decided']);
        self::assertSame(1, $row['gaps']);
        self::assertSame(3, $row['missed']);
        self::assertEqualsWithDelta(0.75, $row['precision'], 1e-9);
        self::assertEqualsWithDelta(0.5, $row['recall'], 1e-9);
    }

    public function testGoldGapsAreCappedAtTen(): void
    {
        $result = new CalibrationResult('suite', 'multiselect', 0.9);
        for ($i = 1; $i <= 12; $i++) {
            $result->addGoldGap('SKU-' . $i . ': Dresser');
        }

        self::assertCount(10, $result->getGoldGaps());
        self::assertSame('SKU-10: Dresser', $result->getGoldGaps()[9]);
    }

    public function testErrorsAreCounted(): void
    {
        $result = new CalibrationResult('color', 'select', 0.9);
        $result->addError();
        $result->addError();

        self::assertSame(2, $result->getErrors());
        self::assertSame(0, $result->getProducts());
    }

    public function testUnknownThresholdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CalibrationResult('color', 'select', 0.9))->record(0.85, true, 1);
    }
}
