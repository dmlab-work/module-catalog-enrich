<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Run;

/**
 * Counters and stop reason of one enrichment run.
 */
class RunReport
{
    public const PRODUCTS = 'products';
    public const REQUESTS = 'requests';
    public const CACHED = 'cached';
    public const INPUT_TOKENS = 'inputTokens';
    public const ASKED = 'asked';
    public const SKIPPED_FINAL = 'skippedFinal';
    public const SKIPPED_BACKOFF = 'skippedBackoff';
    public const ACCEPTED = 'accepted';
    public const NOT_STATED = 'notStated';
    public const UNCERTAIN = 'uncertain';
    public const ERRORS = 'errors';
    public const REJECTED = 'rejected';
    public const COUNTERS = [
        self::PRODUCTS, self::REQUESTS, self::CACHED, self::INPUT_TOKENS, self::ASKED, self::SKIPPED_FINAL,
        self::SKIPPED_BACKOFF, self::ACCEPTED, self::NOT_STATED, self::UNCERTAIN, self::ERRORS,
        self::REJECTED,
    ];

    /**
     * @var array<string,int>
     */
    private array $counters;

    /**
     * Suggestion status => rows created or changed.
     *
     * @var array<string,int>
     */
    private array $suggestions = [];

    /**
     * @var string|null
     */
    private ?string $stopReason = null;

    /**
     * Dry-run lines.
     *
     * @var string[]
     */
    private array $lines = [];

    /**
     * Products that produced a request (in a dry run: would have).
     *
     * @var int
     */
    private int $askedProducts = 0;

    /**
     * Last product id the run went through; null when none.
     *
     * @var int|null
     */
    private ?int $lastProductId = null;

    /**
     * @var bool
     */
    private bool $limitReached = false;

    /**
     * Every counter starts at zero.
     */
    public function __construct()
    {
        $this->counters = array_fill_keys(self::COUNTERS, 0);
    }

    /**
     * Add to a counter.
     *
     * @param string $counter one of the counter constants
     * @param int $by
     * @return void
     */
    public function increment(string $counter, int $by = 1): void
    {
        if (!isset($this->counters[$counter])) {
            throw new \InvalidArgumentException(sprintf('Unknown run counter "%s".', $counter));
        }
        $this->counters[$counter] += $by;
    }

    /**
     * Counter value.
     *
     * @param string $counter one of the counter constants
     * @return int
     */
    public function get(string $counter): int
    {
        return $this->counters[$counter] ?? 0;
    }

    /**
     * All counters.
     *
     * @return array<string,int>
     */
    public function getCounters(): array
    {
        return $this->counters;
    }

    /**
     * Count a created or changed suggestion.
     *
     * @param string $status
     * @return void
     */
    public function addSuggestion(string $status): void
    {
        $this->suggestions[$status] = ($this->suggestions[$status] ?? 0) + 1;
    }

    /**
     * Suggestion status => count.
     *
     * @return array<string,int>
     */
    public function getSuggestions(): array
    {
        return $this->suggestions;
    }

    /**
     * Why the run stopped early (budget, circuit, configuration).
     *
     * @param string $reason
     * @return void
     */
    public function setStopReason(string $reason): void
    {
        $this->stopReason = $reason;
    }

    /**
     * Stop reason; null when the run completed.
     *
     * @return string|null
     */
    public function getStopReason(): ?string
    {
        return $this->stopReason;
    }

    /**
     * Add a dry-run line.
     *
     * @param string $line
     * @return void
     */
    public function addLine(string $line): void
    {
        $this->lines[] = $line;
    }

    /**
     * Dry-run lines.
     *
     * @return string[]
     */
    public function getLines(): array
    {
        return $this->lines;
    }

    /**
     * Count a product that produced a request.
     *
     * @return void
     */
    public function addAskedProduct(): void
    {
        $this->askedProducts++;
    }

    /**
     * Products that produced a request.
     *
     * @return int
     */
    public function getAskedProducts(): int
    {
        return $this->askedProducts;
    }

    /**
     * Record the product the run just went through.
     *
     * @param int $productId
     * @return void
     */
    public function setLastProductId(int $productId): void
    {
        $this->lastProductId = $productId;
    }

    /**
     * Last product id the run went through; null when none.
     *
     * @return int|null
     */
    public function getLastProductId(): ?int
    {
        return $this->lastProductId;
    }

    /**
     * Mark that the run ended at the product limit; later products were not looked at.
     *
     * @return void
     */
    public function setLimitReached(): void
    {
        $this->limitReached = true;
    }

    /**
     * Whether the run ended at the product limit.
     *
     * @return bool
     */
    public function isLimitReached(): bool
    {
        return $this->limitReached;
    }

    /**
     * Add another run's counters and suggestions (dry-run lines and progress are not merged).
     *
     * @param RunReport $other
     * @return void
     */
    public function merge(RunReport $other): void
    {
        foreach ($other->getCounters() as $counter => $value) {
            $this->counters[$counter] += $value;
        }
        foreach ($other->getSuggestions() as $status => $count) {
            $this->suggestions[$status] = ($this->suggestions[$status] ?? 0) + $count;
        }
        $this->askedProducts += $other->getAskedProducts();
    }
}
