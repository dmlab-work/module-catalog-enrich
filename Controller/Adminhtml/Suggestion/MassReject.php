<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Controller\Adminhtml\Suggestion;

use DmLab\CatalogEnrich\Model\Apply\ApplyResult;
use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion as SuggestionResource;
use DmLab\CatalogEnrich\Model\Queue\SuggestionPublisher;
use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion\CollectionFactory;
use DmLab\CatalogEnrich\Model\Suggestion;
use DmLab\CatalogEnrich\Model\Suggestion\Source\Status;
use Magento\Backend\App\Action\Context;
use Magento\Ui\Component\MassAction\Filter;

/**
 * Rejects the selected pending suggestions; a rejected value is never re-suggested for the same fingerprint.
 */
class MassReject extends AbstractMassAction
{
    /**
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param SuggestionPublisher $suggestionPublisher
     * @param SuggestionResource $suggestionResource
     * @param Status $statusSource
     */
    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        SuggestionPublisher $suggestionPublisher,
        private readonly SuggestionResource $suggestionResource,
        Status $statusSource
    ) {
        parent::__construct($context, $filter, $collectionFactory, $suggestionPublisher, $statusSource);
    }

    /**
     * @inheritdoc
     */
    protected function requiredStatus(): string
    {
        return Suggestion::STATUS_PENDING;
    }

    /**
     * @inheritdoc
     */
    protected function process(Suggestion $suggestion): ApplyResult
    {
        $rejected = $this->suggestionResource->transitionStatus(
            (int)$suggestion->getId(),
            Suggestion::STATUS_PENDING,
            Suggestion::STATUS_REJECTED
        );
        if (!$rejected) {
            return new ApplyResult(false, (string)__('The suggestion was changed by another process.'));
        }
        $suggestion->setData('status', Suggestion::STATUS_REJECTED);

        return new ApplyResult(true);
    }
}
