<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Controller\Adminhtml\Product;

use DmLab\CatalogEnrich\Model\Queue\EnrichConsumer;
use DmLab\CatalogEnrich\Model\RuleRepository;
use DmLab\CatalogEnrich\Model\Run\LastRunStore;
use DmLab\CatalogEnrich\Model\Run\StopCondition;
use DmLab\TypeSafeCore\Api\ConfigInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Ui\Component\MassAction\Filter;

/**
 * Product grid mass action: queues the selected product ids for enrichment.
 *
 * Nothing is queued while TypeSafe is not configured or no rule is active: the messages would only be deferred.
 */
class MassEnrich extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'DmLab_CatalogEnrich::run';
    public const CHUNK_SIZE = 100;

    /**
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param PublisherInterface $publisher
     * @param ConfigInterface $coreConfig
     * @param RuleRepository $ruleRepository
     * @param StopCondition $stopCondition
     * @param LastRunStore $lastRunStore
     */
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly PublisherInterface $publisher,
        private readonly ConfigInterface $coreConfig,
        private readonly RuleRepository $ruleRepository,
        private readonly StopCondition $stopCondition,
        private readonly LastRunStore $lastRunStore
    ) {
        parent::__construct($context);
    }

    /**
     * Publish the selection in chunks and return to the product grid.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('catalog/product/index');
        if (!$this->coreConfig->isConfigured()) {
            $this->messageManager->addErrorMessage($this->stopCondition->notConfigured());
            return $redirect;
        }
        if ($this->ruleRepository->getActive() === []) {
            $this->messageManager->addErrorMessage(__(
                'There is no active enrichment rule: set a rule to Shadow, Review or Auto under '
                . 'Catalog > Catalog Enrichment > Rules.'
            ));
            return $redirect;
        }

        try {
            $ids = array_map('intval', $this->filter->getCollection($this->collectionFactory->create())->getAllIds());
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect;
        }
        if (!$ids) {
            $this->messageManager->addErrorMessage(__('Please select products to enrich.'));
            return $redirect;
        }

        // Started before publishing, so no message of this mass action is processed before the reset.
        $this->lastRunStore->startQueue(count($ids));
        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            $this->publisher->publish(EnrichConsumer::TOPIC, $chunk);
        }
        $this->messageManager->addSuccessMessage(__('%1 product(s) queued for enrichment.', count($ids)));

        return $redirect;
    }
}
