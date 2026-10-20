<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Apply;

/**
 * Outcome of an apply or revert.
 */
class ApplyResult
{
    /**
     * @param bool $ok
     * @param string|null $reason why it was refused
     */
    public function __construct(
        private readonly bool $ok,
        private readonly ?string $reason = null
    ) {
    }

    /**
     * Whether the value was written.
     *
     * @return bool
     */
    public function isOk(): bool
    {
        return $this->ok;
    }

    /**
     * Refusal reason; null when ok.
     *
     * @return string|null
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }
}
