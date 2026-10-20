<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Dedup;

use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;

/**
 * Hash of everything that shapes the request for one product × attribute.
 */
class Fingerprint
{
    /**
     * Bump when question building changes, so every pair is re-asked once.
     */
    public const QUESTION_VERSION = 1;

    /**
     * @param CanonicalJson $canonicalJson
     */
    public function __construct(private readonly CanonicalJson $canonicalJson)
    {
    }

    /**
     * Threshold, mode and fill mode are deliberately excluded: they are re-applied locally.
     *
     * @param array<string,mixed> $state exactly what is sent
     * @param AttributeDescriptor $descriptor
     * @param string|array $effectiveInstructions
     * @param string[] $rules
     * @param string $model core ConfigInterface::getModel()
     * @return string sha256 hex
     * @throws \JsonException
     */
    public function compute(
        array $state,
        AttributeDescriptor $descriptor,
        string|array $effectiveInstructions,
        array $rules,
        string $model
    ): string {
        $options = $descriptor->getOptions();
        ksort($options);

        return hash('sha256', $this->canonicalJson->encode([
            'v' => static::QUESTION_VERSION,
            'state' => $state,
            'options' => $options,
            'instructions' => $effectiveInstructions,
            'rules' => array_values($rules),
            'model' => $model,
        ]));
    }
}
