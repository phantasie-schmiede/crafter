<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PSBits\Crafter\Utility\NamingUtility;

/**
 * Class NamingUtilityTest
 *
 * @package PSBits\Crafter\Tests\Unit\Utility
 */
final class NamingUtilityTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pluralDataProvider(): array
    {
        return [
            'consonant plus y becomes ies' => ['category', 'categories'],
            'regular word gets an s'       => ['product', 'products'],
            'sibilant gets es'             => ['address', 'addresses'],
            'vowel plus y gets an s'       => ['day', 'days'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function upperCamelCaseDataProvider(): array
    {
        return [
            'already upper camel case'  => ['DemoShop', 'DemoShop'],
            'single segment'            => ['crafter', 'Crafter'],
            'underscored extension key' => ['demo_shop', 'DemoShop'],
        ];
    }

    #[Test]
    public function buildNamespaceJoinsAllSegments(): void
    {
        self::assertSame(
            'PSBits\DemoShop\Domain\Model',
            NamingUtility::buildNamespace('PSBits', 'DemoShop', 'Domain\Model')
        );
    }

    #[Test]
    public function buildNamespaceOmitsAnEmptySubNamespace(): void
    {
        self::assertSame('PSBits\DemoShop', NamingUtility::buildNamespace('PSBits', 'DemoShop'));
    }

    #[Test]
    public function buildSubNamespaceFromPathDropsClassesAndFileName(): void
    {
        self::assertSame(
            'Domain\Model',
            NamingUtility::buildSubNamespaceFromPath('Classes/Domain/Model/Product.php')
        );
    }

    #[Test]
    public function buildSubNamespaceFromPathReturnsAnEmptyStringForTopLevelClasses(): void
    {
        self::assertSame('', NamingUtility::buildSubNamespaceFromPath('Classes/Product.php'));
    }

    #[Test]
    public function buildTableNameStripsUnderscoresFromTheExtensionKey(): void
    {
        self::assertSame(
            'tx_demoshop_domain_model_product',
            NamingUtility::buildTableName('demo_shop', 'Product')
        );
    }

    #[Test]
    #[DataProvider('pluralDataProvider')]
    public function toPluralAppliesTheExpectedRule(string $value, string $expected): void
    {
        self::assertSame($expected, NamingUtility::toPlural($value));
    }

    #[Test]
    public function toExtensionKeyUnderscoresCamelCase(): void
    {
        self::assertSame('demo_shop', NamingUtility::toExtensionKey('DemoShop'));
    }

    #[Test]
    public function toLowerCamelCaseLowercasesTheFirstLetter(): void
    {
        self::assertSame('demoShop', NamingUtility::toLowerCamelCase('demo_shop'));
    }

    #[Test]
    #[DataProvider('upperCamelCaseDataProvider')]
    public function toUpperCamelCaseNormalizesTheValue(string $value, string $expected): void
    {
        self::assertSame($expected, NamingUtility::toUpperCamelCase($value));
    }
}
