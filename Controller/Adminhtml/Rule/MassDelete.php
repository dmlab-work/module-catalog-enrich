<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Controller\Adminhtml\Rule;

use DmLab\CatalogEnrich\Model\ResourceModel\Rule\CollectionFactory;
use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Model\RuleRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\MassAction\Filter;

/**
 * Deletes the rules selected in the grid.
 */
class MassDelete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'DmLab_CatalogEnrich::rules';

    /**
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param RuleRepository $ruleRepository
     */
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly RuleRepository $ruleRepository
    ) {
        parent::__construct($context);
    }

    /**
     * Delete the selected rules and return to the grid.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/');
        try {
            $rules = $this->filter->getCollection($this->collectionFactory->create())->getItems();
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect;
        }

        $deleted = 0;
        /** @var Rule $rule */
        foreach ($rules as $rule) {
            try {
                $this->ruleRepository->delete($rule);
                $deleted++;
            } catch (LocalizedException $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            }
        }
        $this->messageManager->addSuccessMessage(__('A total of %1 rule(s) have been deleted.', $deleted));

        return $redirect;
    }
}
