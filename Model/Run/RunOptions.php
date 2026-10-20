<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Run;

/**
 * Options of one enrichment run.
 */
class RunOptions
{
    public const DEFAULT_CONSUMER = 'catalog_enrich';

    /**
     * @param string|null $attributeCode limit the run to one active rule
     * @param bool $force ask again regardless of the ledger
     * @param bool $dryRun plan only: no client call, no writes
     * @param string $consumer core usage consumer code
     * @param int|null $productLimit stop before the product that would exceed this many asked products
     */
    public function __construct(
        private readonly ?string $attributeCode = null,
        private readonly bool $force = false,
        private readonly bool $dryRun = false,
        private readonly string $consumer = self::DEFAULT_CONSUMER,
        private readonly ?int $productLimit = null
    ) {
    }

    /**
     * Single attribute to run, or null for every active rule.
     *
     * @return string|null
     */
    public function getAttributeCode(): ?string
    {
        return $this->attributeCode;
    }

    /**
     * Whether the ledger is bypassed.
     *
     * @return bool
     */
    public function isForce(): bool
    {
        return $this->force;
    }

    /**
     * Whether the run only reports what it would ask.
     *
     * @return bool
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * Core usage consumer code.
     *
     * @return string
     */
    public function getConsumer(): string
    {
        return $this->consumer;
    }

    /**
     * Maximum number of products that produce a request; null = no limit.
     *
     * @return int|null
     */
    public function getProductLimit(): ?int
    {
        return $this->productLimit;
    }
}
