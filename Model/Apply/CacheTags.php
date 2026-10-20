<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Apply;

use Magento\Framework\DataObject\IdentityInterface;

/**
 * Fixed tag set for `clean_cache_by_tags`; unlike CacheContext, never deferred by the indexer.
 */
class CacheTags implements IdentityInterface
{
    /**
     * @param string[] $tags
     */
    public function __construct(private readonly array $tags)
    {
    }

    /**
     * @inheritdoc
     */
    public function getIdentities(): array
    {
        return $this->tags;
    }
}
