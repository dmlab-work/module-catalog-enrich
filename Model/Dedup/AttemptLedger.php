<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Dedup;

use DmLab\CatalogEnrich\Model\Attempt;
use DmLab\CatalogEnrich\Model\Decision\Decision;
use DmLab\CatalogEnrich\Model\ResourceModel\Attempt as AttemptResource;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Dedup layer 2: decides whether a product × attribute is sent again and records each attempt.
 */
class AttemptLedger
{
    private const BACKOFF_BASE = 3600;
    private const BACKOFF_CAP = 604800;
    private const ERROR_MESSAGE_LENGTH = 255;
    private const DB_FORMAT = 'Y-m-d H:i:s';
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * Product id => attribute code => row (null = known to be absent).
     *
     * @var array<int, array<string, array|null>>
     */
    private array $rows = [];

    /**
     * @param AttemptResource $attemptResource
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly AttemptResource $attemptResource,
        private readonly DateTime $dateTime
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
        $this->load($productIds, $codes);
    }

    /**
     * Add the rows of the given pairs to the cache.
     *
     * @param int[] $productIds
     * @param string[] $codes
     * @return void
     */
    private function load(array $productIds, array $codes): void
    {
        $loaded = $this->attemptResource->loadForProducts($productIds, $codes);
        foreach ($productIds as $productId) {
            foreach ($codes as $code) {
                $this->rows[(int)$productId][(string)$code] = $loaded[(int)$productId][(string)$code] ?? null;
            }
        }
    }

    /**
     * Whether to ask, skip a final outcome or wait out an error backoff.
     *
     * @param int $productId
     * @param string $code
     * @param string $fingerprint
     * @param bool $force
     * @return LedgerVerdict
     */
    public function verdict(int $productId, string $code, string $fingerprint, bool $force): LedgerVerdict
    {
        $row = $this->row($productId, $code);
        if ($force || $row === null || (string)$row['fingerprint'] !== $fingerprint) {
            return new LedgerVerdict(LedgerVerdict::ASK);
        }
        $outcome = (string)$row['outcome'];
        if (in_array($outcome, Attempt::FINAL_OUTCOMES, true)) {
            $answers = $this->decodeAnswers($row['answer'] ?? null);

            return $answers === null
                ? new LedgerVerdict(LedgerVerdict::ASK)
                : new LedgerVerdict(
                    LedgerVerdict::SKIP_FINAL,
                    $outcome,
                    $answers,
                    is_string($row['model'] ?? null) && $row['model'] !== '' ? $row['model'] : null
                );
        }
        if ($outcome === Attempt::OUTCOME_REJECTED) {
            return new LedgerVerdict(LedgerVerdict::SKIP_REJECTED);
        }
        if ($outcome === Attempt::OUTCOME_ERROR && !empty($row['next_attempt_at'])
            && $this->now() < $this->toTimestamp((string)$row['next_attempt_at'])
        ) {
            return new LedgerVerdict(LedgerVerdict::SKIP_BACKOFF);
        }

        return new LedgerVerdict(LedgerVerdict::ASK);
    }

    /**
     * Record an answered attempt.
     *
     * @param int $productId
     * @param string $code
     * @param string $fingerprint
     * @param Decision $decision
     * @param array<string,array> $normalized the plan's normalized answers
     * @param string $model Response::getModel()
     * @return void
     * @throws \JsonException
     */
    public function recordDecision(
        int $productId,
        string $code,
        string $fingerprint,
        Decision $decision,
        array $normalized,
        string $model
    ): void {
        $this->write($productId, $code, [
            'fingerprint' => $fingerprint,
            'outcome' => $decision->getOutcome(),
            'answer' => json_encode($normalized, self::JSON_FLAGS),
            'model' => $model,
            'error_message' => null,
            'attempts' => $this->nextAttempts($productId, $code, $fingerprint),
            'attempted_at' => $this->format($this->now()),
            'next_attempt_at' => null,
        ]);
    }

    /**
     * Record a failed attempt with exponential backoff (1 h, 2 h, 4 h … capped at 7 days).
     *
     * @param int $productId
     * @param string $code
     * @param string $fingerprint
     * @param string $message
     * @return void
     */
    public function recordError(int $productId, string $code, string $fingerprint, string $message): void
    {
        $attempts = $this->nextAttempts($productId, $code, $fingerprint);
        $delay = (int)min(self::BACKOFF_BASE * 2 ** min($attempts - 1, 20), self::BACKOFF_CAP);
        $now = $this->now();
        $this->write($productId, $code, [
            'fingerprint' => $fingerprint,
            'outcome' => Attempt::OUTCOME_ERROR,
            'answer' => null,
            'model' => null,
            'error_message' => mb_substr($message, 0, self::ERROR_MESSAGE_LENGTH, 'UTF-8'),
            'attempts' => $attempts,
            'attempted_at' => $this->format($now),
            'next_attempt_at' => $this->format($now + $delay),
        ]);
    }

    /**
     * Record data TypeSafe refused; it is not sent again while the fingerprint stays the same.
     *
     * @param int $productId
     * @param string $code
     * @param string $fingerprint
     * @param string $message
     * @return void
     */
    public function recordRejected(int $productId, string $code, string $fingerprint, string $message): void
    {
        $this->write($productId, $code, [
            'fingerprint' => $fingerprint,
            'outcome' => Attempt::OUTCOME_REJECTED,
            'answer' => null,
            'model' => null,
            'error_message' => mb_substr($message, 0, self::ERROR_MESSAGE_LENGTH, 'UTF-8'),
            'attempts' => $this->nextAttempts($productId, $code, $fingerprint),
            'attempted_at' => $this->format($this->now()),
            'next_attempt_at' => null,
        ]);
    }

    /**
     * Change the outcome after a local re-decision; attempts and attempted_at are kept.
     *
     * @param int $productId
     * @param string $code
     * @param string $outcome
     * @return void
     */
    public function updateOutcome(int $productId, string $code, string $outcome): void
    {
        $row = $this->row($productId, $code);
        if ($row === null || (string)$row['outcome'] === $outcome) {
            return;
        }
        $row['outcome'] = $outcome;
        $this->write($productId, $code, $row);
    }

    /**
     * Cached row, loading the pair on demand when it was not prefetched.
     *
     * @param int $productId
     * @param string $code
     * @return array<string,mixed>|null
     */
    private function row(int $productId, string $code): ?array
    {
        if (!isset($this->rows[$productId]) || !array_key_exists($code, $this->rows[$productId])) {
            $this->load([$productId], [$code]);
        }

        return $this->rows[$productId][$code];
    }

    /**
     * Attempts counter: +1 on the same fingerprint, reset to 1 otherwise.
     *
     * @param int $productId
     * @param string $code
     * @param string $fingerprint
     * @return int
     */
    private function nextAttempts(int $productId, string $code, string $fingerprint): int
    {
        $row = $this->row($productId, $code);

        return $row !== null && (string)$row['fingerprint'] === $fingerprint ? (int)$row['attempts'] + 1 : 1;
    }

    /**
     * Upsert and keep the cache in sync.
     *
     * @param int $productId
     * @param string $code
     * @param array<string,mixed> $data
     * @return void
     */
    private function write(int $productId, string $code, array $data): void
    {
        $row = ['product_id' => $productId, 'attribute_code' => $code] + $data;
        unset($row['attempt_id']);
        $this->attemptResource->upsert($row);
        $this->rows[$productId][$code] = $row;
    }

    /**
     * Stored answers, or null when missing or corrupt (the pair is then asked again).
     *
     * @param mixed $json
     * @return array<string,array>|null
     */
    private function decodeAnswers(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        try {
            $answers = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($answers) ? $answers : null;
    }

    /**
     * Current UTC timestamp.
     *
     * @return int
     */
    private function now(): int
    {
        return (int)$this->dateTime->gmtTimestamp();
    }

    /**
     * UTC timestamp of a stored column value.
     *
     * @param string $value
     * @return int
     */
    private function toTimestamp(string $value): int
    {
        $date = \DateTimeImmutable::createFromFormat(self::DB_FORMAT, $value, new \DateTimeZone('UTC'));

        return $date === false ? 0 : $date->getTimestamp();
    }

    /**
     * Column value of a UTC timestamp.
     *
     * @param int $timestamp
     * @return string
     */
    private function format(int $timestamp): string
    {
        return gmdate(self::DB_FORMAT, $timestamp);
    }
}
