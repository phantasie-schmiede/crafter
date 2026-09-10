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
 * Class ModelBlueprint
 *
 * Description of a domain model. Follow-up generators (repository, controller, templates) consume this object
 * instead of asking the console again.
 *
 * @package PSBits\Crafter\Data
 */
readonly class ModelBlueprint
{
    /**
     * @param PropertyDefinition[] $properties
     */
    public function __construct(
        public string $extensionKey,
        public string $name,
        public array  $properties = [],
    ) {
    }

    /**
     * Example: 'ContactBook' => 'contactBook'
     */
    public function getLowerCamelCaseName(): string
    {
        return lcfirst($this->name);
    }

    /**
     * Example: 'ContactBook' => 'tx_contactbook_domain_model_contactbook'
     */
    public function getTableName(): string
    {
        return NamingUtility::buildTableName($this->extensionKey, $this->name);
    }
}
