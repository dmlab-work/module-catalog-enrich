<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Controller\Adminhtml\Suggestion;

use Magento\Backend\App\Action;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * Suggestions grid.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'DmLab_CatalogEnrich::suggestions';

    /**
     * Render the suggestions grid.
     *
     * @return Page
     */
    public function execute(): Page
    {
        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu(self::ADMIN_RESOURCE);
        $page->getConfig()->getTitle()->prepend(__('Enrichment Suggestions'));

        return $page;
    }
}
