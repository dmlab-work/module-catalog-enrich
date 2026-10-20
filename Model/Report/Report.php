<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Report;

/**
 * Fill-rate rows per active rule plus the month's token usage.
 */
class Report
{
    public const COST_PER_MTOK = 0.042;

    /**
     * @param array<int,array<string,mixed>> $rows see ReportBuilder::row()
     * @param array<string,string> $skipped attribute code => reason
     * @param int $enrichTokens
     * @param int $calibrateTokens
     * @param int|null $remainingBudget null = unlimited
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $skipped,
        private readonly int $enrichTokens,
        private readonly int $calibrateTokens,
        private readonly ?int $remainingBudget
    ) {
    }

    /**
     * Rows per active eligible rule.
     *
     * @return array<int, array<string,mixed>>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * Active rules whose attribute is not eligible.
     *
     * @return array<string,string>
     */
    public function getSkipped(): array
    {
        return $this->skipped;
    }

    /**
     * Month tokens of enrichment runs.
     *
     * @return int
     */
    public function getEnrichTokens(): int
    {
        return $this->enrichTokens;
    }

    /**
     * Month tokens of calibration runs.
     *
     * @return int
     */
    public function getCalibrateTokens(): int
    {
        return $this->calibrateTokens;
    }

    /**
     * Remaining monthly budget; null = unlimited.
     *
     * @return int|null
     */
    public function getRemainingBudget(): ?int
    {
        return $this->remainingBudget;
    }

    /**
     * Cost of both consumers' month tokens in USD.
     *
     * @return float
     */
    public function getCost(): float
    {
        return ($this->enrichTokens + $this->calibrateTokens) * self::COST_PER_MTOK / 1_000_000;
    }
}
