<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\State;

use DmLab\CatalogEnrich\Model\Config;

/**
 * Builds the product `state` sent with every question of one product.
 */
class ProductStateBuilder
{
    public const BASE_CODES = ['name', 'short_description', 'description'];
    public const DESCRIPTION_MAX_CHARS = 6000;

    /**
     * @param TextCleaner $textCleaner
     * @param Config $config
     */
    public function __construct(
        private readonly TextCleaner $textCleaner,
        private readonly Config $config
    ) {
    }

    /**
     * Text attribute codes the state is built from, in state order.
     *
     * @return string[]
     */
    public function getCodes(): array
    {
        return array_values(array_unique([...self::BASE_CODES, ...$this->config->getStateAttributes()]));
    }

    /**
     * Cleaned non-empty text values in a fixed key order.
     *
     * @param array<string,mixed> $productData raw values keyed by attribute code
     * @return array<string,string>
     */
    public function build(array $productData): array
    {
        $state = [];
        foreach ($this->getCodes() as $code) {
            $value = $productData[$code] ?? null;
            if (!is_scalar($value)) {
                continue;
            }
            $text = $this->textCleaner->clean((string)$value);
            if ($code === 'description') {
                $text = rtrim($this->textCleaner->cut($text, self::DESCRIPTION_MAX_CHARS));
            }
            if ($text !== '') {
                $state[$code] = $text;
            }
        }

        return $state;
    }

    /**
     * Whether the state carries enough text to ask about.
     *
     * @param array<string,string> $state
     * @return bool
     */
    public function isUsable(array $state): bool
    {
        return ($state['name'] ?? '') !== '' || ($state['description'] ?? '') !== '';
    }
}
