<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Suggestion;

use DmLab\CatalogEnrich\Model\Apply\Applier;
use DmLab\CatalogEnrich\Model\Decision\Decision;
use DmLab\CatalogEnrich\Model\Dedup\RejectionGuard;
use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion as SuggestionResource;
use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Model\Suggestion;
use DmLab\CatalogEnrich\Model\SuggestionFactory;
use Psr\Log\LoggerInterface;

/**
 * Turns an accepted decision into a suggestion according to the rule mode (idempotent per fingerprint).
 *
 * Writes only when a stored row actually changes, so an unchanged run costs one prefetch per chunk.
 */
class SuggestionWriter
{
    private const MODE_STATUS = [
        Rule::MODE_SHADOW => Suggestion::STATUS_SHADOW,
        Rule::MODE_REVIEW => Suggestion::STATUS_PENDING,
        Rule::MODE_AUTO => Suggestion::STATUS_PENDING,
    ];
    private const LIVE = [Suggestion::STATUS_SHADOW, Suggestion::STATUS_PENDING, Suggestion::STATUS_APPLIED];
    private const OPEN = [Suggestion::STATUS_SHADOW, Suggestion::STATUS_PENDING];

    /**
     * Prefetched rows: product id => attribute code => rows, newest first.
     *
     * @var array<int, array<string, array<int, array<string,mixed>>>>
     */
    private array $rows = [];

    /**
     * @param RejectionGuard $rejectionGuard
     * @param SuggestionResource $suggestionResource
     * @param SuggestionFactory $suggestionFactory
     * @param Applier $applier
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RejectionGuard $rejectionGuard,
        private readonly SuggestionResource $suggestionResource,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly Applier $applier,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Load the rows of a chunk in one query; rows of earlier chunks are dropped to bound memory.
     *
     * @param int[] $productIds
     * @param string[] $codes
     * @return void
     */
    public function prefetch(array $productIds, array $codes): void
    {
        $this->rows = [];
        $loaded = $this->suggestionResource->findForProducts($productIds, $codes);
        foreach ($productIds as $productId) {
            foreach ($codes as $code) {
                $this->rows[(int)$productId][(string)$code] = $loaded[(int)$productId][(string)$code] ?? [];
            }
        }
    }

    /**
     * Create or advance the pair's suggestion; returns it only when it was created or changed.
     *
     * A decision that is no longer accepted drops the pair's open (pending/shadow) rows.
     *
     * @param int $productId
     * @param string $code
     * @param Decision $decision
     * @param string $mode rule mode
     * @param string $fingerprint
     * @param string $model answering model
     * @param string|null $currentValue raw store-0 value at decision time
     * @return Suggestion|null
     */
    public function record(
        int $productId,
        string $code,
        Decision $decision,
        string $mode,
        string $fingerprint,
        string $model,
        ?string $currentValue
    ): ?Suggestion {
        if (!isset(self::MODE_STATUS[$mode])) {
            return null;
        }
        $rows = $this->rows($productId, $code);
        if (!$decision->isAccepted()) {
            if ($this->openRows($rows) !== []) {
                $this->suggestionResource->deleteOpen($productId, $code);
                $this->forgetOpen($productId, $code);
            }
            return null;
        }
        if ($this->openRows($rows, $fingerprint) !== []) {
            $this->suggestionResource->deleteStale($productId, $code, $fingerprint);
            $this->forgetOpen($productId, $code, $fingerprint);
            $rows = $this->rows($productId, $code);
        }
        if ($this->rejectionGuard->isBlocked($rows, $fingerprint, (string)$decision->getValue())) {
            return null;
        }

        $existing = $this->findSameFingerprint($rows, $fingerprint);
        if ($existing !== null) {
            return $this->advance($existing, $decision, $mode);
        }

        $currentValue = $currentValue === null || trim($currentValue) === '' ? null : $currentValue;
        $suggestion = $this->suggestionFactory->create();
        $suggestion->setData([
            'product_id' => $productId,
            'attribute_code' => $code,
            'value' => (string)$decision->getValue(),
            'previous_value' => $currentValue,
            'probability' => $this->probability($decision),
            'status' => self::MODE_STATUS[$mode],
            'fingerprint' => $fingerprint,
            'model' => $model,
        ]);
        $this->suggestionResource->save($suggestion);
        $this->remember($suggestion);
        if ($mode === Rule::MODE_AUTO) {
            $this->autoApply($suggestion);
        }

        return $suggestion;
    }

    /**
     * Drop open suggestions of products whose value was filled meanwhile (empty_only rules skip them).
     *
     * @param string $code
     * @param int[] $productIds
     * @return void
     */
    public function dropFilled(string $code, array $productIds): void
    {
        $productIds = array_values(array_filter(
            $productIds,
            fn ($productId): bool => !isset($this->rows[(int)$productId][$code])
                || $this->openRows($this->rows[(int)$productId][$code]) !== []
        ));
        if ($productIds === []) {
            return;
        }
        $this->suggestionResource->deleteOpenForProducts($code, $productIds);
        foreach ($productIds as $productId) {
            $this->forgetOpen((int)$productId, $code);
        }
    }

    /**
     * Refresh an open row of the same fingerprint to the current decision and move it to the rule mode's status.
     *
     * @param Suggestion $suggestion
     * @param Decision $decision
     * @param string $mode
     * @return Suggestion|null
     */
    private function advance(Suggestion $suggestion, Decision $decision, string $mode): ?Suggestion
    {
        $status = $suggestion->getStatus();
        if ($status === Suggestion::STATUS_APPLIED) {
            return null;
        }
        $value = (string)$decision->getValue();
        $refreshed = (string)$suggestion->getData('value') !== $value;
        if ($refreshed) {
            $suggestion->setData('value', $value);
            $suggestion->setData('probability', $this->probability($decision));
        }
        if ($mode === Rule::MODE_AUTO && $this->autoApply($suggestion)) {
            return $suggestion;
        }
        $target = self::MODE_STATUS[$mode];
        if ($status !== $target) {
            $suggestion->setData('status', $target);
        } elseif (!$refreshed) {
            return null;
        }

        if (!$this->suggestionResource->saveIfStatus($suggestion, $status)) {
            return null;
        }
        $this->remember($suggestion);

        return $suggestion;
    }

    /**
     * Stored probability (column scale).
     *
     * @param Decision $decision
     * @return float|null
     */
    private function probability(Decision $decision): ?float
    {
        $probability = $decision->getProbability();

        return $probability === null ? null : round($probability, 4);
    }

    /**
     * Apply; a refusal is logged and leaves the row for a human.
     *
     * @param Suggestion $suggestion
     * @return bool
     */
    private function autoApply(Suggestion $suggestion): bool
    {
        $result = $this->applier->apply($suggestion);
        if ($result->isOk()) {
            $this->remember($suggestion);
        } else {
            $this->logger->warning('DmLab_CatalogEnrich: auto-apply refused, suggestion left pending.', [
                'product_id' => $suggestion->getProductId(),
                'attribute_code' => $suggestion->getAttributeCode(),
                'reason' => $result->getReason(),
            ]);
        }

        return $result->isOk();
    }

    /**
     * Newest live (shadow/pending/applied) row with this fingerprint.
     *
     * @param array $rows suggestion rows, newest first
     * @param string $fingerprint
     * @return Suggestion|null
     */
    private function findSameFingerprint(array $rows, string $fingerprint): ?Suggestion
    {
        foreach ($rows as $row) {
            if ((string)($row['fingerprint'] ?? '') !== $fingerprint
                || !in_array((string)($row['status'] ?? ''), self::LIVE, true)
            ) {
                continue;
            }
            $suggestion = $this->suggestionFactory->create();
            $suggestion->setData($row);

            return $suggestion;
        }

        return null;
    }

    /**
     * Rows of the pair: prefetched, or read now (not cached) when the pair was not prefetched.
     *
     * @param int $productId
     * @param string $code
     * @return array<int, array<string,mixed>>
     */
    private function rows(int $productId, string $code): array
    {
        return $this->rows[$productId][$code] ?? $this->suggestionResource->findFor($productId, $code);
    }

    /**
     * Open (pending/shadow) rows, optionally only those with another fingerprint.
     *
     * @param array $rows suggestion rows, newest first
     * @param string|null $exceptFingerprint
     * @return array<int, array<string,mixed>>
     */
    private function openRows(array $rows, ?string $exceptFingerprint = null): array
    {
        return array_filter(
            $rows,
            static fn (array $row): bool => in_array((string)($row['status'] ?? ''), self::OPEN, true)
                && ($exceptFingerprint === null || (string)($row['fingerprint'] ?? '') !== $exceptFingerprint)
        );
    }

    /**
     * Drop deleted open rows from the prefetched pair.
     *
     * @param int $productId
     * @param string $code
     * @param string|null $exceptFingerprint open rows with this fingerprint were kept
     * @return void
     */
    private function forgetOpen(int $productId, string $code, ?string $exceptFingerprint = null): void
    {
        if (!isset($this->rows[$productId][$code])) {
            return;
        }
        $deleted = $this->openRows($this->rows[$productId][$code], $exceptFingerprint);
        $this->rows[$productId][$code] = array_values(array_diff_key($this->rows[$productId][$code], $deleted));
    }

    /**
     * Put a created or changed row into the prefetched pair.
     *
     * @param Suggestion $suggestion
     * @return void
     */
    private function remember(Suggestion $suggestion): void
    {
        $productId = $suggestion->getProductId();
        $code = $suggestion->getAttributeCode();
        if (!isset($this->rows[$productId][$code])) {
            return;
        }
        $id = (int)$suggestion->getId();
        foreach ($this->rows[$productId][$code] as $i => $row) {
            if ((int)($row['suggestion_id'] ?? 0) === $id) {
                $this->rows[$productId][$code][$i] = $suggestion->getData();

                return;
            }
        }
        array_unshift($this->rows[$productId][$code], $suggestion->getData());
    }
}
