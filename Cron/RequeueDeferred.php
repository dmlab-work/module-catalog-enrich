<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Cron;

use DmLab\CatalogEnrich\Controller\Adminhtml\Product\MassEnrich;
use DmLab\CatalogEnrich\Model\Queue\EnrichConsumer;
use DmLab\CatalogEnrich\Model\ResourceModel\DeferredQueue;
use DmLab\TypeSafeCore\Api\ConfigInterface;
use Magento\Framework\MessageQueue\PublisherInterface;

/**
 * Hourly: queue again the products a stopped queued run deferred (budget, key, circuit).
 *
 * While TypeSafe is not configured the ids stay deferred: queued again they would only stop and be deferred anew.
 */
class RequeueDeferred
{
    public const LIMIT = 5000;

    /**
     * @param DeferredQueue $deferredQueue
     * @param PublisherInterface $publisher
     * @param ConfigInterface $coreConfig
     */
    public function __construct(
        private readonly DeferredQueue $deferredQueue,
        private readonly PublisherInterface $publisher,
        private readonly ConfigInterface $coreConfig
    ) {
    }

    /**
     * Publish deferred ids in mass-action sized batches.
     *
     * @return void
     */
    public function execute(): void
    {
        if (!$this->coreConfig->isConfigured()) {
            return;
        }
        $ids = $this->deferredQueue->take(self::LIMIT);
        foreach (array_chunk($ids, MassEnrich::CHUNK_SIZE) as $chunk) {
            $this->publisher->publish(EnrichConsumer::TOPIC, $chunk);
        }
    }
}
