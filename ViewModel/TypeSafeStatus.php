<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\ViewModel;

use DmLab\CatalogEnrich\Model\Run\StopCondition;
use DmLab\TypeSafeCore\Api\ConfigInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Warning above the enrichment grids while TypeSafe is disabled or has no API key.
 */
class TypeSafeStatus implements ArgumentInterface
{
    /**
     * @param ConfigInterface $coreConfig
     * @param StopCondition $stopCondition
     */
    public function __construct(
        private readonly ConfigInterface $coreConfig,
        private readonly StopCondition $stopCondition
    ) {
    }

    /**
     * Unescaped warning, or null when TypeSafe is configured.
     *
     * @return string|null
     */
    public function getNotConfiguredMessage(): ?string
    {
        return $this->coreConfig->isConfigured() ? null : (string)$this->stopCondition->notConfigured();
    }
}
