<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;

/**
 * Queues an approve or revert of many suggestions as JSON messages of id chunks.
 */
class SuggestionPublisher
{
    public const CHUNK_SIZE = 100;

    /**
     * @param PublisherInterface $publisher
     * @param SuggestionQueueSummary $summary
     */
    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly SuggestionQueueSummary $summary
    ) {
    }

    /**
     * Publish the ids in chunks; the queue summary restarts for this mass action.
     *
     * @param string $operation one of the SuggestionConsumer::OPERATION_* constants
     * @param int[] $suggestionIds
     * @return void
     * @throws \JsonException
     */
    public function publish(string $operation, array $suggestionIds): void
    {
        if (!isset(SuggestionConsumer::REQUIRED_STATUS[$operation])) {
            throw new \InvalidArgumentException(sprintf('Unknown suggestion operation "%s".', $operation));
        }
        $ids = array_values(array_unique(array_map('intval', $suggestionIds)));
        $this->summary->start(count($ids));
        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            $this->publisher->publish(
                SuggestionConsumer::TOPIC,
                json_encode(['operation' => $operation, 'ids' => $chunk], JSON_THROW_ON_ERROR)
            );
        }
    }
}
