<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * The attribute cannot be enriched; the message carries the reason.
 */
class IneligibleAttributeException extends LocalizedException
{
}
