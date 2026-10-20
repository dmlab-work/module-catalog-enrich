<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Controller\Adminhtml\Rule;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\ResultFactory;

/**
 * New rule: the edit form without an id.
 */
class NewAction extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'DmLab_CatalogEnrich::rules';

    /**
     * Forward to the empty edit form.
     *
     * @return Forward
     */
    public function execute(): Forward
    {
        /** @var Forward $forward */
        $forward = $this->resultFactory->create(ResultFactory::TYPE_FORWARD);

        return $forward->forward('edit');
    }
}
