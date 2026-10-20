<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Typed reader over the `dmlab_catalog_enrich` config section (default scope only).
 */
class Config
{
    public const XML_PATH_STATE_ATTRIBUTES = 'dmlab_catalog_enrich/general/state_attributes';
    public const XML_PATH_INCLUDE_NOT_VISIBLE = 'dmlab_catalog_enrich/general/include_not_visible';
    public const XML_PATH_CRON_ENABLED = 'dmlab_catalog_enrich/cron/enabled';
    public const XML_PATH_CRON_PRODUCTS_PER_RUN = 'dmlab_catalog_enrich/cron/products_per_run';

    public const DEFAULT_PRODUCTS_PER_RUN = 500;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Extra text attribute codes sent in the product state, in config order, without duplicates.
     *
     * @return string[]
     */
    public function getStateAttributes(): array
    {
        $codes = array_map('trim', explode(',', $this->getString(self::XML_PATH_STATE_ATTRIBUTES)));

        return array_values(array_unique(array_filter($codes, static fn (string $code): bool => $code !== '')));
    }

    /**
     * Whether products with visibility "Not Visible Individually" are enriched too.
     *
     * @return bool
     */
    public function isIncludeNotVisible(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_INCLUDE_NOT_VISIBLE);
    }

    /**
     * Whether the nightly cron job runs.
     *
     * @return bool
     */
    public function isCronEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CRON_ENABLED);
    }

    /**
     * Products per nightly run, at least 1.
     *
     * @return int
     */
    public function getCronProductsPerRun(): int
    {
        $value = $this->getString(self::XML_PATH_CRON_PRODUCTS_PER_RUN);

        return max(1, is_numeric($value) ? (int)$value : self::DEFAULT_PRODUCTS_PER_RUN);
    }

    /**
     * Trimmed string value; '' when unset.
     *
     * @param string $path
     * @return string
     */
    private function getString(string $path): string
    {
        $value = $this->scopeConfig->getValue($path);

        return is_scalar($value) ? trim((string)$value) : '';
    }
}
