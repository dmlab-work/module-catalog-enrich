<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Suggested value for one product × attribute.
 */
class Suggestion extends AbstractModel
{
    public const STATUS_SHADOW = 'shadow';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REVERTED = 'reverted';
    public const STATUSES = [
        self::STATUS_SHADOW,
        self::STATUS_PENDING,
        self::STATUS_APPLIED,
        self::STATUS_REJECTED,
        self::STATUS_REVERTED,
    ];

    /**
     * @var string
     */
    protected $_eventPrefix = 'dmlab_catalog_enrich_suggestion';

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(ResourceModel\Suggestion::class);
    }

    /**
     * Product id.
     *
     * @return int
     */
    public function getProductId(): int
    {
        return (int)$this->getData('product_id');
    }

    /**
     * Attribute code.
     *
     * @return string
     */
    public function getAttributeCode(): string
    {
        return (string)$this->getData('attribute_code');
    }

    /**
     * Suggested raw value.
     *
     * @return string
     */
    public function getValue(): string
    {
        return (string)$this->getData('value');
    }

    /**
     * Raw value at creation; null when it was empty.
     *
     * @return string|null
     */
    public function getPreviousValue(): ?string
    {
        $value = $this->getData('previous_value');

        return $value === null ? null : (string)$value;
    }

    /**
     * One of the STATUS_* constants.
     *
     * @return string
     */
    public function getStatus(): string
    {
        return (string)$this->getData('status');
    }

    /**
     * Fingerprint of the request that produced it.
     *
     * @return string
     */
    public function getFingerprint(): string
    {
        return (string)$this->getData('fingerprint');
    }
}
