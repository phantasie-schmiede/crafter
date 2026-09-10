<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PSBits\Crafter\Utility\PhpUtility;

/**
 * Class PhpUtilityTest
 *
 * @package PSBits\Crafter\Tests\Unit\Utility
 */
final class PhpUtilityTest extends TestCase
{
    #[Test]
    public function alignArgumentsPadsTheTypesToTheLongestOne(): void
    {
        self::assertSame(
            [
                'protected readonly ProductRepository $productRepository,',
                'protected readonly Context           $context,',
            ],
            PhpUtility::alignArguments([
                'protected readonly ProductRepository $productRepository,',
                'protected readonly Context $context,',
            ])
        );
    }

    #[Test]
    public function getShortClassNameReturnsTheLastSegment(): void
    {
        self::assertSame(
            'AbstractEntity',
            PhpUtility::getShortClassName('TYPO3\CMS\Extbase\DomainObject\AbstractEntity')
        );
    }

    #[Test]
    public function getShortClassNameReturnsGlobalClassesUnchanged(): void
    {
        self::assertSame('DateTime', PhpUtility::getShortClassName('\DateTime'));
    }

    #[Test]
    public function renderImportsOmitsClassesOfTheTargetNamespace(): void
    {
        self::assertSame(
            'use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;',
            PhpUtility::renderImports(
                [
                    'PSBits\DemoShop\Domain\Model\Category',
                    'TYPO3\CMS\Extbase\DomainObject\AbstractEntity',
                ],
                'PSBits\DemoShop\Domain\Model'
            )
        );
    }

    /**
     * The backslash must be treated like a character below the letters, which is how php-cs-fixer sorts imports.
     */
    #[Test]
    public function renderImportsSortsLikePhpCsFixer(): void
    {
        self::assertSame(
            implode("\n", [
                'use PSBits\DemoShop\Domain\Model\Product;',
                'use PSBits\DemoShop\Domain\Repository\ProductRepository;',
                'use PSBits\DemoShopping;',
            ]),
            PhpUtility::renderImports([
                'PSBits\DemoShopping',
                'PSBits\DemoShop\Domain\Repository\ProductRepository',
                'PSBits\DemoShop\Domain\Model\Product',
            ])
        );
    }
}
