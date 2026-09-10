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
 * Class PropertyDefinition
 *
 * A single property of a domain model, described independently of its rendering.
 *
 * @package PSBits\Crafter\Data
 */
readonly class PropertyDefinition
{
    public function __construct(
        public string $name,
        public string $type,
        public bool   $required = false,
        public string $linkedClass = '',
        public string $foreignField = '',
    ) {
    }

    /**
     * Method name suffix, example: 'contactGroups' => 'ContactGroups'
     */
    public function getMethodSuffix(): string
    {
        return ucfirst($this->name);
    }

    /**
     * Singular method name suffix used by add*() and remove*(), example: 'contactGroups' => 'ContactGroup'
     */
    public function getSingularMethodSuffix(): string
    {
        $suffix = $this->getMethodSuffix();

        if (str_ends_with($suffix, 'ies')) {
            return substr($suffix, 0, -3) . 'y';
        }

        if (str_ends_with($suffix, 'sses') || str_ends_with($suffix, 'shes') || str_ends_with($suffix, 'ches')) {
            return substr($suffix, 0, -2);
        }

        if (str_ends_with($suffix, 's') && !str_ends_with($suffix, 'ss')) {
            return substr($suffix, 0, -1);
        }

        return $suffix;
    }

    /**
     * Database column name, example: 'contactGroups' => 'contact_groups'
     */
    public function getColumnName(): string
    {
        return NamingUtility::toLowerCaseUnderscored($this->name);
    }
}
