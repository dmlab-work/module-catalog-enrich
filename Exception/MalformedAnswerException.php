<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * A TypeSafe response (or stored answer) does not match the question plan.
 */
class MalformedAnswerException extends LocalizedException
{
}
