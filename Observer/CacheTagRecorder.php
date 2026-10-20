<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Observer;

use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Records the cache tags realtime indexers clean (`clean_cache_by_tags`) between start() and stop().
 */
class CacheTagRecorder implements ObserverInterface
{
    /**
     * Recorded tags; null while not recording.
     *
     * @var string[]|null
     */
    private ?array $tags = null;

    /**
     * Start recording.
     *
     * @return void
     */
    public function start(): void
    {
        $this->tags = [];
    }

    /**
     * Stop recording.
     *
     * @return string[] unique tags recorded since start()
     */
    public function stop(): array
    {
        $tags = array_values(array_unique($this->tags ?? []));
        $this->tags = null;

        return $tags;
    }

    /**
     * Record the identities of the cleaned object.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $object = $observer->getEvent()->getData('object');
        if ($this->tags !== null && $object instanceof IdentityInterface) {
            $this->tags = [...$this->tags, ...$object->getIdentities()];
        }
    }
}
