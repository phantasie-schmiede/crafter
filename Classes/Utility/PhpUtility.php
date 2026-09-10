<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Utility;

/**
 * Class PhpUtility
 *
 * Helpers for assembling PHP source code that are shared by all class generators.
 *
 * @package PSBits\Crafter\Utility
 */
class PhpUtility
{
    /**
     * Aligns the parameter names of a promoted constructor, matching the house style of the reference extensions.
     *
     * @param string[] $arguments
     *
     * @return string[]
     */
    public static function alignArguments(array $arguments): array
    {
        $longestType = 0;

        foreach ($arguments as $argument) {
            $longestType = max($longestType, strlen(self::getTypePart($argument)));
        }

        return array_map(static function(string $argument) use ($longestType): string {
            $type = self::getTypePart($argument);

            return $type . str_repeat(' ', $longestType - strlen($type) + 1)
                . trim(substr($argument, strlen($type)));
        }, $arguments);
    }

    /**
     * Example: 'TYPO3\CMS\Extbase\DomainObject\AbstractEntity' => 'AbstractEntity'
     */
    public static function getShortClassName(string $className): string
    {
        $className = trim($className, '\\');
        $position  = strrpos($className, '\\');

        return false === $position ? $className : substr($className, $position + 1);
    }

    /**
     * Builds the use statements. Classes of the target namespace and classes without a namespace are omitted,
     * because importing them would be redundant.
     *
     * @param string[] $classNames
     */
    public static function renderImports(array $classNames, string $targetNamespace = ''): string
    {
        $imports = [];

        foreach ($classNames as $className) {
            $className = trim($className, '\\');

            if ('' === $className) {
                continue;
            }

            $namespace = self::getNamespace($className);

            if ($namespace === $targetNamespace) {
                continue;
            }

            $imports[$className] = 'use ' . $className . ';';
        }

        uksort($imports, static fn(string $a, string $b): int => strcasecmp(
            str_replace('\\', ' ', $a),
            str_replace('\\', ' ', $b)
        ));

        return implode("\n", $imports);
    }

    /**
     * Example: 'PSBits\ContactBook\Domain\Model\Contact' => 'PSBits\ContactBook\Domain\Model'
     */
    private static function getNamespace(string $className): string
    {
        $position = strrpos($className, '\\');

        return false === $position ? '' : substr($className, 0, $position);
    }

    /**
     * Example: 'protected readonly ContactRepository $contactRepository,' => 'protected readonly ContactRepository'
     */
    private static function getTypePart(string $argument): string
    {
        $position = strpos($argument, ' $');

        return false === $position ? $argument : substr($argument, 0, $position);
    }
}
