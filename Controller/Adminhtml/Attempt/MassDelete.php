<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Controller\Adminhtml\Attempt;

use DmLab\CatalogEnrich\Model\ResourceModel\Attempt as AttemptResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProviderInterface;
use Magento\Ui\Component\MassAction\Filter;

/**
 * "Ask again on next run": deletes the selected ledger rows, so the next run sends those pairs again.
 */
class MassDelete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'DmLab_CatalogEnrich::run';

    /**
     * @param Context $context
     * @param Filter $filter
     * @param FilterBuilder $filterBuilder
     * @param AttemptResource $attemptResource
     */
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly FilterBuilder $filterBuilder,
        private readonly AttemptResource $attemptResource
    ) {
        parent::__construct($context);
    }

    /**
     * Delete the selected attempts and return to the grid.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/');
        try {
            $ids = $this->selectedIds();
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect;
        }

        $deleted = $this->attemptResource->deleteByIds($ids);
        $this->messageManager->addSuccessMessage(
            __('%1 attempt(s) cleared; these products are asked again on the next run.', $deleted)
        );

        return $redirect;
    }

    /**
     * Ids of the selection under the grid's filters, read as one id column: "select all" loads no grid rows.
     *
     * @return int[]
     * @throws LocalizedException
     */
    private function selectedIds(): array
    {
        $selected = $this->getRequest()->getParam(Filter::SELECTED_PARAM);
        $excluded = $this->getRequest()->getParam(Filter::EXCLUDED_PARAM);
        if ($excluded !== 'false'
            && !(is_array($excluded) && $excluded !== [])
            && !(is_array($selected) && $selected !== [])
        ) {
            throw new LocalizedException(__('An item needs to be selected. Select and try again.'));
        }
        // Preparing the listing applies the grid's filters to its data provider.
        $component = $this->filter->getComponent();
        $this->filter->prepareComponent($component);
        $dataProvider = $component->getContext()->getDataProvider();
        if (is_array($excluded) && $excluded !== []) {
            $this->filterIds($dataProvider, 'nin', $excluded);
        } elseif ($excluded !== 'false') {
            $this->filterIds($dataProvider, 'in', $selected);
        }
        $result = $dataProvider->getSearchResult();
        if (!$result instanceof AbstractCollection) {
            throw new LocalizedException(__('The selection cannot be resolved.'));
        }

        return array_map('intval', $result->getAllIds());
    }

    /**
     * Restrict the data provider to (or exclude) the given grid row ids.
     *
     * @param DataProviderInterface $dataProvider
     * @param string $condition `in` or `nin`
     * @param array $ids
     * @return void
     */
    private function filterIds(DataProviderInterface $dataProvider, string $condition, array $ids): void
    {
        $dataProvider->addFilter(
            $this->filterBuilder->setConditionType($condition)
                ->setField($dataProvider->getPrimaryFieldName())
                ->setValue($ids)
                ->create()
        );
    }
}
