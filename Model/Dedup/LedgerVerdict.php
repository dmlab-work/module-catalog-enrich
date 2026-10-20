<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Dedup;

/**
 * What the ledger says to do with one product × attribute.
 */
class LedgerVerdict
{
    public const ASK = 'ask';
    public const SKIP_FINAL = 'skip_final';
    public const SKIP_BACKOFF = 'skip_backoff';
    public const SKIP_REJECTED = 'skip_rejected';

    /**
     * @param string $type one of the type constants
     * @param string|null $outcome stored final outcome (SKIP_FINAL only)
     * @param array<string,array> $answers stored normalized answers (SKIP_FINAL only)
     * @param string|null $model model that gave the stored answers (SKIP_FINAL only)
     */
    public function __construct(
        private readonly string $type,
        private readonly ?string $outcome = null,
        private readonly array $answers = [],
        private readonly ?string $model = null
    ) {
        if (!in_array($type, [self::ASK, self::SKIP_FINAL, self::SKIP_BACKOFF, self::SKIP_REJECTED], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown ledger verdict "%s".', $type));
        }
    }

    /**
     * One of the type constants.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Whether the pair must be sent.
     *
     * @return bool
     */
    public function isAsk(): bool
    {
        return $this->type === self::ASK;
    }

    /**
     * Stored final outcome.
     *
     * @return string|null
     */
    public function getOutcome(): ?string
    {
        return $this->outcome;
    }

    /**
     * Stored normalized answers, for a local re-decision.
     *
     * @return array<string,array>
     */
    public function getAnswers(): array
    {
        return $this->answers;
    }

    /**
     * Model that gave the stored answers.
     *
     * @return string|null
     */
    public function getModel(): ?string
    {
        return $this->model;
    }
}
