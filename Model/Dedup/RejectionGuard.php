<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Dedup;

use DmLab\CatalogEnrich\Model\Suggestion;

/**
 * A human rejection or revert blocks any value for that fingerprint and that value for any fingerprint.
 */
class RejectionGuard
{
    private const BLOCKING = [Suggestion::STATUS_REJECTED, Suggestion::STATUS_REVERTED];

    /**
     * Whether a rejected or reverted row of the pair has this fingerprint or this value (as an option id set).
     *
     * @param array $rows the pair's suggestion rows
     * @param string $fingerprint
     * @param string $value raw suggested value
     * @return bool
     */
    public function isBlocked(array $rows, string $fingerprint, string $value): bool
    {
        $ids = $this->idSet($value);
        foreach ($rows as $row) {
            if (!in_array((string)($row['status'] ?? ''), self::BLOCKING, true)) {
                continue;
            }
            if ((string)($row['fingerprint'] ?? '') === $fingerprint
                || ($ids !== [] && $this->idSet((string)($row['value'] ?? '')) === $ids)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sorted distinct parts of a comma-joined raw value.
     *
     * @param string $value
     * @return string[]
     */
    private function idSet(string $value): array
    {
        $ids = [];
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $ids[ctype_digit($part) ? (string)(int)$part : $part] = true;
            }
        }
        $ids = array_map('strval', array_keys($ids));
        sort($ids, SORT_STRING);

        return $ids;
    }
}
