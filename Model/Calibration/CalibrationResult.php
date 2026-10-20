<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Calibration;

use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;

/**
 * Calibration tallies per threshold over products whose stored value is the gold answer.
 */
class CalibrationResult
{
    public const THRESHOLDS = [0.5, 0.7, 0.8, 0.9, 0.95];
    public const MIN_PRECISION = 0.95;
    public const MIN_DECIDED = 10;
    public const MAX_GOLD_GAPS = 10;

    /**
     * Threshold key => [decided, correct, gaps, missed].
     *
     * @var array<string, array{decided: int, correct: int, gaps: int, missed: int}>
     */
    private array $counts = [];

    /**
     * @var string[]
     */
    private array $goldGaps = [];

    /**
     * @var int
     */
    private int $products = 0;

    /**
     * @var int
     */
    private int $errors = 0;

    /**
     * @var int
     */
    private int $inputTokens = 0;

    /**
     * Products left out because their 0 counts as empty for the rule.
     *
     * @var int
     */
    private int $excludedZero = 0;

    /**
     * @param string $attributeCode
     * @param string $input one of the AttributeDescriptor::INPUT_* constants
     * @param float $gapThreshold threshold the gold-gap list is collected at
     */
    public function __construct(
        private readonly string $attributeCode,
        private readonly string $input,
        private readonly float $gapThreshold
    ) {
        foreach (self::THRESHOLDS as $threshold) {
            $this->counts[$this->key($threshold)] = ['decided' => 0, 'correct' => 0, 'gaps' => 0, 'missed' => 0];
        }
    }

    /**
     * Attribute code.
     *
     * @return string
     */
    public function getAttributeCode(): string
    {
        return $this->attributeCode;
    }

    /**
     * One of the AttributeDescriptor::INPUT_* constants.
     *
     * @return string
     */
    public function getInput(): string
    {
        return $this->input;
    }

    /**
     * Whether the per-option multiselect metrics apply.
     *
     * @return bool
     */
    public function isMultiselect(): bool
    {
        return $this->input === AttributeDescriptor::INPUT_MULTISELECT;
    }

    /**
     * Threshold the gold-gap list is collected at.
     *
     * @return float
     */
    public function getGapThreshold(): float
    {
        return $this->gapThreshold;
    }

    /**
     * Count one scored product with its input tokens.
     *
     * @param int $inputTokens
     * @return void
     */
    public function addProduct(int $inputTokens): void
    {
        $this->products++;
        $this->inputTokens += $inputTokens;
    }

    /**
     * Products left out because their 0 counts as empty for the rule.
     *
     * @param int $count
     * @return void
     */
    public function setExcludedZero(int $count): void
    {
        $this->excludedZero = $count;
    }

    /**
     * Products left out because their 0 counts as empty for the rule.
     *
     * @return int
     */
    public function getExcludedZero(): int
    {
        return $this->excludedZero;
    }

    /**
     * Count one product that could not be scored.
     *
     * @return void
     */
    public function addError(): void
    {
        $this->errors++;
    }

    /**
     * Record one product's outcome at a threshold.
     *
     * @param float $threshold one of THRESHOLDS
     * @param bool $decided
     * @param int $correct correct answers (select/boolean: 0 or 1; multiselect: confirmed options)
     * @param int $gaps multiselect: yes but gold lacks it
     * @param int $missed multiselect: gold has it but not yes
     * @return void
     */
    public function record(float $threshold, bool $decided, int $correct, int $gaps = 0, int $missed = 0): void
    {
        $key = $this->key($threshold);
        if (!isset($this->counts[$key])) {
            throw new \InvalidArgumentException(sprintf('Unknown calibration threshold %s.', $key));
        }
        $this->counts[$key]['decided'] += (int)$decided;
        $this->counts[$key]['correct'] += $correct;
        $this->counts[$key]['gaps'] += $gaps;
        $this->counts[$key]['missed'] += $missed;
    }

    /**
     * Add a `SKU: option` gold-gap line; only the first MAX_GOLD_GAPS are kept.
     *
     * @param string $line
     * @return void
     */
    public function addGoldGap(string $line): void
    {
        if (count($this->goldGaps) < self::MAX_GOLD_GAPS) {
            $this->goldGaps[] = $line;
        }
    }

    /**
     * Gold-gap lines.
     *
     * @return string[]
     */
    public function getGoldGaps(): array
    {
        return $this->goldGaps;
    }

    /**
     * Products scored.
     *
     * @return int
     */
    public function getProducts(): int
    {
        return $this->products;
    }

    /**
     * Products that could not be scored.
     *
     * @return int
     */
    public function getErrors(): int
    {
        return $this->errors;
    }

    /**
     * Input tokens of the scored products.
     *
     * @return int
     */
    public function getInputTokens(): int
    {
        return $this->inputTokens;
    }

    /**
     * Metrics per threshold, ascending; ratios are null when undefined.
     *
     * @return array<int, array{threshold: float, decided: int, coverage: ?float, correct: int, precision: ?float,
     *     gaps: int, missed: int, recall: ?float}>
     */
    public function getRows(): array
    {
        $rows = [];
        foreach (self::THRESHOLDS as $threshold) {
            $c = $this->counts[$this->key($threshold)];
            // Multiselect precision is per option: confirmed / (confirmed + gaps).
            $judged = $this->isMultiselect() ? $c['correct'] + $c['gaps'] : $c['decided'];
            $rows[] = [
                'threshold' => $threshold,
                'decided' => $c['decided'],
                'coverage' => $this->ratio($c['decided'], $this->products),
                'correct' => $c['correct'],
                'precision' => $this->ratio($c['correct'], $judged),
                'gaps' => $c['gaps'],
                'missed' => $c['missed'],
                'recall' => $this->ratio($c['correct'], $c['correct'] + $c['missed']),
            ];
        }

        return $rows;
    }

    /**
     * Lowest rule-valid threshold (> 0.5) with precision ≥ MIN_PRECISION and at least MIN_DECIDED decided products.
     *
     * @return float|null
     */
    public function suggestedThreshold(): ?float
    {
        foreach ($this->getRows() as $row) {
            if ($row['threshold'] > 0.5 && $row['decided'] >= self::MIN_DECIDED && $row['precision'] !== null
                && $row['precision'] >= self::MIN_PRECISION
            ) {
                return $row['threshold'];
            }
        }

        return null;
    }

    /**
     * Ratio, or null for a zero denominator.
     *
     * @param int $part
     * @param int $whole
     * @return float|null
     */
    private function ratio(int $part, int $whole): ?float
    {
        return $whole > 0 ? $part / $whole : null;
    }

    /**
     * Array key of a threshold.
     *
     * @param float $threshold
     * @return string
     */
    private function key(float $threshold): string
    {
        return sprintf('%.2f', $threshold);
    }
}
