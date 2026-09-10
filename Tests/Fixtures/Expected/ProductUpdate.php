<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Demo Shop.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\DemoShop\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class ProductUpdate
 *
 * @package PSBits\DemoShop\EventListener
 */
#[AsEventListener(identifier: 'demo_shop/productUpdate')]
class ProductUpdate
{
}
