<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Queue;

use DmLab\CatalogEnrich\Model\Apply\Applier;
use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion\CollectionFactory;
use DmLab\CatalogEnrich\Model\Suggestion;
use Psr\Log\LoggerInterface;

/**
 * Approves or reverts queued suggestions one by one through the Applier; refusals are logged.
 */
class SuggestionConsumer
{
    public const TOPIC = 'dmlab.catalog_enrich.suggestions';
    public const OPERATION_APPROVE = 'approve';
    public const OPERATION_REVERT = 'revert';
    public const REQUIRED_STATUS = [
        self::OPERATION_APPROVE => Suggestion::STATUS_PENDING,
        self::OPERATION_REVERT => Suggestion::STATUS_APPLIED,
    ];

    /**
     * @param CollectionFactory $collectionFactory
     * @param Applier $applier
     * @param LoggerInterface $logger
     * @param SuggestionQueueSummary $summary
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly Applier $applier,
        private readonly LoggerInterface $logger,
        private readonly SuggestionQueueSummary $summary
    ) {
    }

    /**
     * Process one message: `{"operation": "approve"|"revert", "ids": [int, ...]}`.
     *
     * A malformed message is logged and dropped; it would fail the same way on every retry.
     *
     * @param string $message
     * @return void
     */
    public function process(string $message): void
    {
        try {
            $data = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }
        $operation = is_array($data) && is_string($data['operation'] ?? null) ? $data['operation'] : '';
        $ids = $this->ids(is_array($data) ? $data['ids'] ?? null : null);
        if (!isset(self::REQUIRED_STATUS[$operation]) || $ids === []) {
            $this->logger->warning('DmLab_CatalogEnrich: malformed suggestion queue message dropped.', [
                'message' => mb_substr($message, 0, 255, 'UTF-8'),
            ]);
            return;
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('suggestion_id', ['in' => $ids]);
        $done = 0;
        $refused = [];
        /** @var Suggestion $suggestion */
        foreach ($collection->getItems() as $suggestion) {
            $id = (int)$suggestion->getId();
            unset($ids[$id]);
            $reason = $this->run($operation, $suggestion);
            if ($reason === null) {
                $done++;
            } else {
                $refused[$id] = $reason;
            }
        }
        foreach ($ids as $id) {
            $refused[$id] = 'The suggestion no longer exists.';
        }

        foreach ($refused as $id => $reason) {
            $this->logger->warning(sprintf(
                'DmLab_CatalogEnrich: queued %s of suggestion #%d refused: %s',
                $operation,
                $id,
                $reason
            ));
        }
        $this->logger->info(sprintf(
            'DmLab_CatalogEnrich: queued %s finished, %d done, %d refused.',
            $operation,
            $done,
            count($refused)
        ));
        $this->summary->add($operation, $done, count($refused));
    }

    /**
     * Run the operation on one suggestion; the refusal reason, or null when done.
     *
     * @param string $operation
     * @param Suggestion $suggestion
     * @return string|null
     */
    private function run(string $operation, Suggestion $suggestion): ?string
    {
        $required = self::REQUIRED_STATUS[$operation];
        if ($suggestion->getStatus() !== $required) {
            return sprintf('status is "%s", only "%s" can be processed.', $suggestion->getStatus(), $required);
        }
        $result = $operation === self::OPERATION_APPROVE
            ? $this->applier->apply($suggestion)
            : $this->applier->revert($suggestion);

        return $result->isOk() ? null : (string)$result->getReason();
    }

    /**
     * Positive integer ids, keyed by themselves.
     *
     * @param mixed $values
     * @return array<int,int>
     */
    private function ids(mixed $values): array
    {
        $ids = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if (is_numeric($value) && (int)$value > 0) {
                $ids[(int)$value] = (int)$value;
            }
        }

        return $ids;
    }
}
