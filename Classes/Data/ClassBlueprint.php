<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Data;

use PSBits\Crafter\Utility\NamingUtility;

/**
 * Class ClassBlueprint
 *
 * Everything crafter:make:service, crafter:make:view-helper, crafter:make:event-listener and
 * crafter:make:backend-controller need to describe a single PHP class. What a class type looks like by default is
 * defined in crafter.classTypes, this blueprint only carries the answers of the developer.
 *
 * @package PSBits\Crafter\Data
 */
readonly class ClassBlueprint
{
    /**
     * @param string   $type         key below crafter.classTypes
     * @param string[] $dependencies fully qualified class names injected via the constructor
     * @param string[] $imports      additional fully qualified class names
     * @param string[] $methods      rendered method bodies, inserted in the given order
     */
    public function __construct(
        public string $extensionKey,
        public string $name,
        public string $type,
        public array  $dependencies = [],
        public array  $imports = [],
        public array  $methods = [],
        public string $subPath = '',
    ) {
    }

    /**
     * Example: 'Import' of type backendController => 'ImportController'
     */
    public function getClassName(string $suffix): string
    {
        return true === str_ends_with($this->name, $suffix) ? $this->name : $this->name . $suffix;
    }

    /**
     * Example: 'ContactImport' => 'contactImport'
     */
    public function getLowerCamelCaseName(): string
    {
        return NamingUtility::toLowerCamelCase($this->name);
    }
}
