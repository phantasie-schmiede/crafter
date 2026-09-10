<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Demo Shop.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\DemoShop\Service;

use PSBits\DemoShop\Domain\Repository\ProductRepository;
use TYPO3\CMS\Core\Context\Context;

/**
 * Class PriceService
 *
 * @package PSBits\DemoShop\Service
 */
readonly class PriceService
{
    public function __construct(
        protected Context           $context,
        protected ProductRepository $productRepository,
    ) {
    }
}
