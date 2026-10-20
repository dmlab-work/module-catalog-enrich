<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Controller\Adminhtml\Suggestion;

use DmLab\CatalogEnrich\Model\Apply\ApplyResult;
use DmLab\CatalogEnrich\Model\Queue\SuggestionPublisher;
use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion\CollectionFactory;
use DmLab\CatalogEnrich\Model\Suggestion;
use DmLab\CatalogEnrich\Model\Suggestion\Source\Status;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\MassAction\Filter;

/**
 * Runs one transition over the selected suggestions that are in the required status.
 *
 * A queueable transition over more than SYNC_LIMIT suggestions is queued instead of run in the request.
 */
abstract class AbstractMassAction extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'DmLab_CatalogEnrich::suggestions_apply';
    public const SYNC_LIMIT = 50;

    private const MAX_REASONS = 5;

    /**
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param SuggestionPublisher $suggestionPublisher
     * @param Status $statusSource
     */
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly SuggestionPublisher $suggestionPublisher,
        private readonly Status $statusSource
    ) {
        parent::__construct($context);
    }

    /**
     * Status a suggestion must have to be processed.
     *
     * @return string
     */
    abstract protected function requiredStatus(): string;

    /**
     * Run the transition on one suggestion.
     *
     * @param Suggestion $suggestion
     * @return ApplyResult
     */
    abstract protected function process(Suggestion $suggestion): ApplyResult;

    /**
     * Queue operation for large selections; null = always run in the request.
     *
     * @return string|null one of the SuggestionConsumer::OPERATION_* constants
     */
    protected function queueOperation(): ?string
    {
        return null;
    }

    /**
     * Process the selection and report "done / skipped" with the first skip reasons.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/');
        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            if ($this->queueOperation() !== null && $this->queue($collection->getAllIds())) {
                return $redirect;
            }
            $suggestions = $collection->getItems();
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect;
        }

        $done = 0;
        $reasons = [];
        /** @var Suggestion $suggestion */
        foreach ($suggestions as $suggestion) {
            $reason = $this->run($suggestion);
            if ($reason === null) {
                $done++;
                continue;
            }
            $reasons[] = (string)__('Suggestion #%1: %2', $suggestion->getId(), $reason);
        }

        $summary = __('%1 done, %2 skipped.', $done, count($reasons));
        if ($done > 0) {
            $this->messageManager->addSuccessMessage($summary);
        } else {
            $this->messageManager->addWarningMessage($summary);
        }
        foreach (array_slice($reasons, 0, self::MAX_REASONS) as $reason) {
            $this->messageManager->addWarningMessage($reason);
        }

        return $redirect;
    }

    /**
     * Queue a selection larger than SYNC_LIMIT; false when it is to be processed now.
     *
     * @param array $ids
     * @return bool
     * @throws \JsonException
     */
    private function queue(array $ids): bool
    {
        if (count($ids) <= self::SYNC_LIMIT) {
            return false;
        }
        $this->suggestionPublisher->publish((string)$this->queueOperation(), array_map('intval', $ids));
        $this->messageManager->addSuccessMessage(__(
            '%1 suggestion(s) queued. They are processed in the background; refusals are written to the Magento log.',
            count($ids)
        ));

        return true;
    }

    /**
     * Process one suggestion; the skip reason, or null when done.
     *
     * @param Suggestion $suggestion
     * @return string|null
     */
    private function run(Suggestion $suggestion): ?string
    {
        if ($suggestion->getStatus() !== $this->requiredStatus()) {
            return (string)__(
                'status is "%1", only "%2" can be processed.',
                $this->statusLabel($suggestion->getStatus()),
                $this->statusLabel($this->requiredStatus())
            );
        }
        try {
            $result = $this->process($suggestion);
        } catch (\Exception $e) {
            return $e->getMessage();
        }

        return $result->isOk() ? null : (string)$result->getReason();
    }

    /**
     * Translated label of a status; the raw code when unknown.
     *
     * @param string $status
     * @return string
     */
    private function statusLabel(string $status): string
    {
        foreach ($this->statusSource->toOptionArray() as $option) {
            if ($option['value'] === $status) {
                return (string)$option['label'];
            }
        }

        return $status;
    }
}
