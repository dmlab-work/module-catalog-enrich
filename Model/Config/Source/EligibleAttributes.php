<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Config\Source;

use DmLab\CatalogEnrich\Model\Attribute\AttributeCatalog;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Attributes the module can enrich, as `Label (code)`.
 */
class EligibleAttributes implements OptionSourceInterface
{
    /**
     * @param AttributeCatalog $attributeCatalog
     */
    public function __construct(
        private readonly AttributeCatalog $attributeCatalog
    ) {
    }

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->attributeCatalog->getEligible() as $descriptor) {
            $options[] = [
                'value' => $descriptor->getCode(),
                'label' => sprintf('%s (%s)', $descriptor->getLabel(), $descriptor->getCode()),
            ];
        }

        return $options;
    }
}
