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
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;

/**
 * Deletes one rule.
 */
class Delete extends Action implements HttpPostActionInterface
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
     * Delete the rule and return to the grid.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/');
        $ruleId = (int)$this->getRequest()->getParam('id');
        if ($ruleId <= 0) {
            $this->messageManager->addErrorMessage(__('We can\'t find a rule to delete.'));
            return $redirect;
        }

        try {
            $this->ruleRepository->delete($this->ruleRepository->getById($ruleId));
            $this->messageManager->addSuccessMessage(__('The rule has been deleted.'));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $redirect;
    }
}
