<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Controller\Adminhtml\Suggestion;

use DmLab\CatalogEnrich\Model\Apply\Applier;
use DmLab\CatalogEnrich\Model\Apply\ApplyResult;
use DmLab\CatalogEnrich\Model\Queue\SuggestionConsumer;
use DmLab\CatalogEnrich\Model\Queue\SuggestionPublisher;
use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion\CollectionFactory;
use DmLab\CatalogEnrich\Model\Suggestion;
use DmLab\CatalogEnrich\Model\Suggestion\Source\Status;
use Magento\Backend\App\Action\Context;
use Magento\Ui\Component\MassAction\Filter;

/**
 * Reverts the selected applied suggestions.
 */
class MassRevert extends AbstractMassAction
{
    /**
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param SuggestionPublisher $suggestionPublisher
     * @param Applier $applier
     * @param Status $statusSource
     */
    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        SuggestionPublisher $suggestionPublisher,
        private readonly Applier $applier,
        Status $statusSource
    ) {
        parent::__construct($context, $filter, $collectionFactory, $suggestionPublisher, $statusSource);
    }

    /**
     * @inheritdoc
     */
    protected function requiredStatus(): string
    {
        return Suggestion::STATUS_APPLIED;
    }

    /**
     * @inheritdoc
     */
    protected function process(Suggestion $suggestion): ApplyResult
    {
        return $this->applier->revert($suggestion);
    }

    /**
     * @inheritdoc
     */
    protected function queueOperation(): ?string
    {
        return SuggestionConsumer::OPERATION_REVERT;
    }
}
