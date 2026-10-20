<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Ledger row: the last attempt for one product × attribute.
 */
class Attempt extends AbstractModel
{
    public const OUTCOME_ACCEPTED = 'accepted';
    public const OUTCOME_NOT_STATED = 'not_stated';
    public const OUTCOME_UNCERTAIN = 'uncertain';
    public const OUTCOME_ERROR = 'error';
    /** TypeSafe refused this exact data (400/413/422 or not encodable): not re-sent until the fingerprint changes. */
    public const OUTCOME_REJECTED = 'rejected';
    public const FINAL_OUTCOMES = [self::OUTCOME_ACCEPTED, self::OUTCOME_NOT_STATED, self::OUTCOME_UNCERTAIN];

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(ResourceModel\Attempt::class);
    }
}
