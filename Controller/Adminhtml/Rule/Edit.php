<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Controller\Adminhtml\Rule;

use DmLab\CatalogEnrich\Model\RuleRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Rule form (new or existing).
 */
class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'DmLab_CatalogEnrich::rules';

    /**
     * @param Context $context
     * @param RuleRepository $ruleRepository
     */
    public function __construct(
        Context $context,
        private readonly RuleRepository $ruleRepository
    ) {
        parent::__construct($context);
    }

    /**
     * Render the form; an unknown id returns to the grid.
     *
     * @return Page|Redirect
     */
    public function execute(): Page|Redirect
    {
        $ruleId = (int)$this->getRequest()->getParam('id');
        $title = __('New Rule');
        if ($ruleId > 0) {
            try {
                $title = __('Edit Rule: %1', $this->ruleRepository->getById($ruleId)->getAttributeCode());
            } catch (NoSuchEntityException $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
                return $this->resultRedirectFactory->create()->setPath('*/*/');
            }
        }

        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu(self::ADMIN_RESOURCE);
        $page->getConfig()->getTitle()->prepend($title);

        return $page;
    }
}
