<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Run;

use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\Rule;

/**
 * An active rule resolved for a run: its attribute and the attribute sets it applies to.
 */
class AttributePlan
{
    /**
     * @param Rule $rule
     * @param AttributeDescriptor $descriptor
     * @param int[] $setIds effective attribute set ids
     */
    public function __construct(
        private readonly Rule $rule,
        private readonly AttributeDescriptor $descriptor,
        private readonly array $setIds
    ) {
    }

    /**
     * Rule.
     *
     * @return Rule
     */
    public function getRule(): Rule
    {
        return $this->rule;
    }

    /**
     * Attribute descriptor.
     *
     * @return AttributeDescriptor
     */
    public function getDescriptor(): AttributeDescriptor
    {
        return $this->descriptor;
    }

    /**
     * Effective attribute set ids.
     *
     * @return int[]
     */
    public function getSetIds(): array
    {
        return $this->setIds;
    }

    /**
     * Whether the plan applies to a product of this attribute set.
     *
     * @param int $setId
     * @return bool
     */
    public function appliesToSet(int $setId): bool
    {
        return in_array($setId, $this->setIds, true);
    }
}
