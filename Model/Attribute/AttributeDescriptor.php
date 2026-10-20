<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Attribute;

/**
 * Immutable view of an enrichable product attribute and its store-0 option set.
 */
class AttributeDescriptor
{
    public const INPUT_SELECT = 'select';
    public const INPUT_MULTISELECT = 'multiselect';
    public const INPUT_BOOLEAN = 'boolean';

    public const BOOLEAN_OPTIONS = [1 => 'Yes', 0 => 'No'];

    /**
     * @var array<int, string>
     */
    private readonly array $options;

    /**
     * @param int $attributeId
     * @param string $code
     * @param string $label
     * @param string $input
     * @param string $backendTable
     * @param array<int|string,mixed> $options option id => label; ignored for boolean
     */
    public function __construct(
        private readonly int $attributeId,
        private readonly string $code,
        private readonly string $label,
        private readonly string $input,
        private readonly string $backendTable,
        array $options = []
    ) {
        if (!in_array($input, [self::INPUT_SELECT, self::INPUT_MULTISELECT, self::INPUT_BOOLEAN], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported attribute input "%s".', $input));
        }
        $this->options = $input === self::INPUT_BOOLEAN ? self::BOOLEAN_OPTIONS : $this->normalizeOptions($options);
    }

    /**
     * Attribute id.
     *
     * @return int
     */
    public function getAttributeId(): int
    {
        return $this->attributeId;
    }

    /**
     * Attribute code.
     *
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Store-0 frontend label.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * One of the INPUT_* constants.
     *
     * @return string
     */
    public function getInput(): string
    {
        return $this->input;
    }

    /**
     * Backend value table.
     *
     * @return string
     */
    public function getBackendTable(): string
    {
        return $this->backendTable;
    }

    /**
     * Option id => store-0 label, in source order.
     *
     * @return array<int, string>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Trim labels, drop empty ones and keep the first id of labels equal by trim + case-insensitive compare.
     *
     * @param array<int|string,mixed> $options
     * @return array<int, string>
     */
    private function normalizeOptions(array $options): array
    {
        $result = [];
        $seen = [];
        foreach ($options as $id => $label) {
            if (!is_numeric($id) || !is_scalar($label)) {
                continue;
            }
            $label = trim((string)$label);
            $key = mb_strtolower($label, 'UTF-8');
            if ($label === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[(int)$id] = $label;
        }

        return $result;
    }
}
