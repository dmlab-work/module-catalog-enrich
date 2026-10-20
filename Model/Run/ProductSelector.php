<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Run;

use DmLab\CatalogEnrich\Model\Config;
use DmLab\CatalogEnrich\Model\ResourceModel\CatalogQuery;
use Magento\Framework\Exception\LocalizedException;

/**
 * Product ids a run can enrich: those in an attribute set used by any plan, visible individually unless configured.
 */
class ProductSelector
{
    /**
     * @param CatalogQuery $catalogQuery
     * @param AttributePlanner $attributePlanner
     * @param Config $config
     */
    public function __construct(
        private readonly CatalogQuery $catalogQuery,
        private readonly AttributePlanner $attributePlanner,
        private readonly Config $config
    ) {
    }

    /**
     * Ascending product ids after the cursor.
     *
     * @param string[]|null $skus null = any product
     * @param int|null $limit null = no limit
     * @param int $afterId
     * @param string|null $attributeCode limit to the sets of this attribute's rule
     * @return int[]
     * @throws LocalizedException the attribute has no active rule
     */
    public function select(?array $skus, ?int $limit, int $afterId = 0, ?string $attributeCode = null): array
    {
        $setIds = [];
        foreach ($this->attributePlanner->plan($attributeCode) as $plan) {
            $setIds[] = $plan->getSetIds();
        }
        $setIds = array_values(array_unique(array_merge([], ...$setIds)));
        sort($setIds);

        return $this->catalogQuery->fetchIds(
            $afterId,
            $limit,
            $setIds,
            $skus ?? [],
            !$this->config->isIncludeNotVisible()
        );
    }
}
