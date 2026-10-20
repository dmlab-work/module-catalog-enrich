<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Run;

use DmLab\TypeSafeCore\Exception\AuthenticationException;
use DmLab\TypeSafeCore\Exception\BudgetExceededException;
use DmLab\TypeSafeCore\Exception\CircuitOpenException;
use DmLab\TypeSafeCore\Exception\NotConfiguredException;
use Magento\Framework\Phrase;

/**
 * Core failures that stop a whole run or calibration instead of failing one product.
 */
class StopCondition
{
    private const EXCEPTIONS = [
        BudgetExceededException::class,
        CircuitOpenException::class,
        NotConfiguredException::class,
        AuthenticationException::class,
    ];

    /**
     * Whether a per-product result stops the run.
     *
     * @param mixed $result
     * @return bool
     */
    public function isStop(mixed $result): bool
    {
        foreach (self::EXCEPTIONS as $class) {
            if ($result instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Message for a disabled or keyless core.
     *
     * @return Phrase
     */
    public function notConfigured(): Phrase
    {
        return __(
            'TypeSafe is not configured: enable it and set the API key under '
            . 'Stores > Configuration > DMLab > TypeSafe.'
        );
    }
}
