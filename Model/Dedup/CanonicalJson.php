<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Dedup;

/**
 * Deterministic JSON: associative arrays are key-sorted recursively, lists keep their order.
 */
class CanonicalJson
{
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * Encode a value canonically.
     *
     * @param mixed $value
     * @return string
     * @throws \JsonException
     */
    public function encode(mixed $value): string
    {
        return json_encode($this->canonicalize($value), self::FLAGS);
    }

    /**
     * Key-sort associative arrays at every depth.
     *
     * @param mixed $value
     * @return mixed
     */
    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn ($item) => $this->canonicalize($item), $value);
    }
}
