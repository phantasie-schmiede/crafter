<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Utility;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class NamingUtility
 *
 * Central place for all conversions between extension keys, class names, namespaces and database table names.
 * Generators must not build such strings on their own to keep naming consistent across all commands.
 *
 * @package PSBits\Crafter\Utility
 */
class NamingUtility
{
    /**
     * Example: 'PSBits', 'ContactBook', 'Domain\Model' => 'PSBits\ContactBook\Domain\Model'
     */
    public static function buildNamespace(string $vendor, string $extensionName, string $subNamespace = ''): string
    {
        $parts = [
            $vendor,
            $extensionName,
        ];

        if ('' !== $subNamespace) {
            $parts[] = trim($subNamespace, '\\');
        }

        return implode('\\', $parts);
    }

    /**
     * Derives the namespace part from a relative class file path.
     * Example: 'Classes/Domain/Model/Contact.php' => 'Domain\Model'
     */
    public static function buildSubNamespaceFromPath(string $relativeFilePath): string
    {
        $pathElements = explode('/', trim(str_replace('\\', '/', $relativeFilePath), '/'));

        // Remove the leading 'Classes' directory and the filename.
        array_shift($pathElements);
        array_pop($pathElements);

        return implode('\\', $pathElements);
    }

    /**
     * Example: 'contact_book', 'Contact' => 'tx_contactbook_domain_model_contact'
     */
    public static function buildTableName(string $extensionKey, string $modelName): string
    {
        return implode('_', [
            'tx',
            str_replace('_', '', $extensionKey),
            'domain',
            'model',
            strtolower(self::toUpperCamelCase($modelName)),
        ]);
    }

    /**
     * Example: 'ContactBook' => 'contact_book'
     */
    public static function toExtensionKey(string $extensionName): string
    {
        return GeneralUtility::camelCaseToLowerCaseUnderscored($extensionName);
    }

    /**
     * Example: 'contact_book' => 'contactBook'
     */
    public static function toLowerCamelCase(string $value): string
    {
        return lcfirst(self::toUpperCamelCase($value));
    }

    /**
     * Example: 'contactGroups' => 'contact_groups'
     */
    public static function toLowerCaseUnderscored(string $value): string
    {
        return GeneralUtility::camelCaseToLowerCaseUnderscored($value);
    }

    /**
     * Naive English pluralization, sufficient for variable names in generated code.
     * Example: 'category' => 'categories', 'address' => 'addresses', 'product' => 'products'
     */
    public static function toPlural(string $value): string
    {
        if ('' === $value) {
            return $value;
        }

        if (1 === preg_match('/[^aeiou]y$/i', $value)) {
            return substr($value, 0, -1) . 'ies';
        }

        if (1 === preg_match('/(s|x|z|ch|sh)$/i', $value)) {
            return $value . 'es';
        }

        return $value . 's';
    }

    /**
     * Example: 'contact_book' => 'ContactBook'
     */
    public static function toUpperCamelCase(string $value): string
    {
        if (1 === preg_match('/^[A-Z][A-Za-z0-9]*$/', $value)) {
            return $value;
        }

        return GeneralUtility::underscoredToUpperCamelCase(
            GeneralUtility::camelCaseToLowerCaseUnderscored($value)
        );
    }
}
