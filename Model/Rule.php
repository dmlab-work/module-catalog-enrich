<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model;

use DmLab\CatalogEnrich\Model\Decision\DecisionEngine;
use Magento\Framework\Model\AbstractModel;

/**
 * Per-attribute enrichment rule.
 */
class Rule extends AbstractModel
{
    public const MODE_OFF = 'off';
    public const MODE_SHADOW = 'shadow';
    public const MODE_REVIEW = 'review';
    public const MODE_AUTO = 'auto';
    public const MODES = [self::MODE_OFF, self::MODE_SHADOW, self::MODE_REVIEW, self::MODE_AUTO];
    /** Mode of a new rule: decisions are recorded, nothing is applied until reviewed. */
    public const DEFAULT_MODE = self::MODE_SHADOW;

    public const FILL_EMPTY_ONLY = DecisionEngine::FILL_EMPTY_ONLY;
    public const FILL_ADD_MISSING = DecisionEngine::FILL_ADD_MISSING;
    public const FILL_MODES = [self::FILL_EMPTY_ONLY, self::FILL_ADD_MISSING];

    public const DEFAULT_THRESHOLD = 0.9;

    /**
     * @var string
     */
    protected $_eventPrefix = 'dmlab_catalog_enrich_rule';

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(ResourceModel\Rule::class);
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
     * One of the MODE_* constants.
     *
     * @return string
     */
    public function getMode(): string
    {
        return (string)($this->getData('mode') ?? self::DEFAULT_MODE);
    }

    /**
     * Acceptance threshold.
     *
     * @return float
     */
    public function getThreshold(): float
    {
        $threshold = $this->getData('threshold');

        return is_numeric($threshold) ? (float)$threshold : self::DEFAULT_THRESHOLD;
    }

    /**
     * Custom instructions; null means the default template.
     *
     * @return string|null
     */
    public function getInstructions(): ?string
    {
        $text = trim((string)$this->getData('instructions'));

        return $text === '' ? null : $text;
    }

    /**
     * Extra rules, one per non-empty line.
     *
     * @return string[]
     */
    public function getRules(): array
    {
        $lines = array_map('trim', preg_split('/\R/u', (string)$this->getData('extra_rules')) ?: []);

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    /**
     * Attribute set ids the rule is limited to; empty = every set with the attribute.
     *
     * @return int[]
     */
    public function getAttributeSetIds(): array
    {
        $ids = [];
        foreach (explode(',', (string)$this->getData('attribute_set_ids')) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $ids[] = (int)$part;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * One of the FILL_* constants.
     *
     * @return string
     */
    public function getFillMode(): string
    {
        return (string)($this->getData('fill_mode') ?? self::FILL_EMPTY_ONLY);
    }

    /**
     * Whether a stored boolean 0 counts as empty (boolean attributes only).
     *
     * @return bool
     */
    public function isTreatZeroAsEmpty(): bool
    {
        return (bool)(int)$this->getData('treat_zero_as_empty');
    }

    /**
     * Whether a raw stored value counts as empty for this rule.
     *
     * @param mixed $value
     * @return bool
     */
    public function isEmptyValue(mixed $value): bool
    {
        if (!is_scalar($value)) {
            return true;
        }
        $value = trim((string)$value);

        return $value === '' || ($value === '0' && $this->isTreatZeroAsEmpty());
    }

    /**
     * True unless the mode is off.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->getMode() !== self::MODE_OFF;
    }
}
