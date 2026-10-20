<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Attempt\Source;

use DmLab\CatalogEnrich\Model\Attempt;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Attempt ledger outcomes.
 */
class Outcome implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Attempt::OUTCOME_ACCEPTED, 'label' => __('Accepted')],
            ['value' => Attempt::OUTCOME_NOT_STATED, 'label' => __('Not stated')],
            ['value' => Attempt::OUTCOME_UNCERTAIN, 'label' => __('Uncertain')],
            ['value' => Attempt::OUTCOME_ERROR, 'label' => __('Error')],
            ['value' => Attempt::OUTCOME_REJECTED, 'label' => __('Rejected by TypeSafe')],
        ];
    }
}
