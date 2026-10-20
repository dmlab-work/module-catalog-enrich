<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Decision;

/**
 * Outcome of one attribute for one product.
 */
class Decision
{
    public const ACCEPTED = 'accepted';
    public const NOT_STATED = 'not_stated';
    public const UNCERTAIN = 'uncertain';

    /**
     * @param string $outcome one of the outcome constants
     * @param string|null $value option id, comma-joined ids or `1`/`0`; set only when accepted
     * @param float|null $probability set only when accepted
     */
    public function __construct(
        private readonly string $outcome,
        private readonly ?string $value = null,
        private readonly ?float $probability = null
    ) {
        if (!in_array($outcome, [self::ACCEPTED, self::NOT_STATED, self::UNCERTAIN], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown decision outcome "%s".', $outcome));
        }
    }

    /**
     * One of the outcome constants.
     *
     * @return string
     */
    public function getOutcome(): string
    {
        return $this->outcome;
    }

    /**
     * Whether a value was accepted.
     *
     * @return bool
     */
    public function isAccepted(): bool
    {
        return $this->outcome === self::ACCEPTED;
    }

    /**
     * Value to write (store 0 raw format).
     *
     * @return string|null
     */
    public function getValue(): ?string
    {
        return $this->value;
    }

    /**
     * Probability of the accepted value.
     *
     * @return float|null
     */
    public function getProbability(): ?float
    {
        return $this->probability;
    }
}
