<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Plugin\Catalog;

use DmLab\CatalogEnrich\Controller\Adminhtml\Product\MassEnrich;
use Magento\Catalog\Ui\Component\Product\MassAction;
use Magento\Framework\AuthorizationInterface;

/**
 * Hides "Enrich attributes" in the product grid for roles without the run resource.
 *
 * The product grid mass action component filters actions by type and ignores `actionDisable`.
 */
class ProductMassActionAcl
{
    public const ACTION_TYPE = 'dmlab_catalog_enrich';

    /**
     * @param AuthorizationInterface $authorization
     */
    public function __construct(
        private readonly AuthorizationInterface $authorization
    ) {
    }

    /**
     * Deny the enrich action without `DmLab_CatalogEnrich::run`.
     *
     * @param MassAction $subject
     * @param bool $result
     * @param string $actionType
     * @return bool
     */
    public function afterIsActionAllowed(MassAction $subject, bool $result, $actionType): bool
    {
        if ($actionType !== self::ACTION_TYPE) {
            return $result;
        }

        return $result && $this->authorization->isAllowed(MassEnrich::ADMIN_RESOURCE);
    }
}
