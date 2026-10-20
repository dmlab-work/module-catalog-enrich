<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Question;

use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;

/**
 * Question instruction texts: default templates, placeholders and structured extra rules.
 */
class InstructionsBuilder
{
    public const PLACEHOLDER_ATTRIBUTE = '{attribute}';
    public const PLACEHOLDER_OPTION = '{option}';

    private const TEMPLATES = [
        AttributeDescriptor::INPUT_SELECT => 'What is the {attribute} of this product?',
        AttributeDescriptor::INPUT_MULTISELECT => 'Does "{option}" apply to the {attribute} of this product?',
        AttributeDescriptor::INPUT_BOOLEAN => 'Is "{attribute}" true for this product?',
    ];

    /**
     * Default template for the attribute's input type, placeholders kept.
     *
     * @param AttributeDescriptor $descriptor
     * @return string
     */
    public function defaultText(AttributeDescriptor $descriptor): string
    {
        return self::TEMPLATES[$descriptor->getInput()];
    }

    /**
     * Resolve placeholders; with rules → `['question' => text, 'rules' => rules]`, otherwise the plain text.
     *
     * A null label leaves its placeholder in place.
     *
     * @param string $text
     * @param string[] $rules
     * @param string|null $optionLabel
     * @param string|null $attributeLabel store-0 label, lower-cased here
     * @return string|array
     */
    public function build(
        string $text,
        array $rules,
        ?string $optionLabel = null,
        ?string $attributeLabel = null
    ): string|array {
        $replace = [];
        if ($attributeLabel !== null) {
            $replace[self::PLACEHOLDER_ATTRIBUTE] = mb_strtolower(trim($attributeLabel), 'UTF-8');
        }
        if ($optionLabel !== null) {
            $replace[self::PLACEHOLDER_OPTION] = $optionLabel;
        }
        $text = strtr($text, $replace);
        $rules = array_values($rules);

        return $rules === [] ? $text : ['question' => $text, 'rules' => $rules];
    }

    /**
     * One rule per line, trimmed, empty lines dropped.
     *
     * @param string|null $text
     * @return string[]
     */
    public function parseRules(?string $text): array
    {
        if ($text === null) {
            return [];
        }
        $lines = array_map('trim', preg_split('/\R/u', $text) ?: []);

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }
}
