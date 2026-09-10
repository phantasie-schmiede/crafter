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
 * Class ControllerBlueprint
 *
 * Everything crafter:make:controller needs to know, resolved before any file is touched.
 *
 * @package PSBits\Crafter\Data
 */
readonly class ControllerBlueprint
{
    /**
     * @param string[]             $actions
     * @param PropertyDefinition[] $properties
     */
    public function __construct(
        public string  $extensionKey,
        public string  $name,
        public string  $modelName = '',
        public array   $actions = [],
        public array   $properties = [],
        public bool    $withTemplates = true,
        public bool    $withLanguageFiles = true,
        public bool    $withPlugin = true,
        public string  $pluginName = '',
    ) {
    }

    /**
     * Example: 'Product' => 'product'
     */
    public function getModelVariable(): string
    {
        return lcfirst($this->getModelName());
    }

    public function getModelName(): string
    {
        return '' !== $this->modelName ? $this->modelName : $this->name;
    }

    /**
     * Example: 'Product' => 'products'
     */
    public function getPluralVariable(): string
    {
        return NamingUtility::toPlural($this->getModelVariable());
    }

    /**
     * Name of the plugin registered in ExtensionInformation, example: 'Product' => 'Products'
     */
    public function getPluginName(): string
    {
        return '' !== $this->pluginName ? $this->pluginName : NamingUtility::toPlural($this->name);
    }

    /**
     * Property name used as label in list and detail views, empty when the model has no usable property.
     */
    public function getLabelProperty(): string
    {
        foreach ($this->properties as $property) {
            if (true === $property->required && 'string' === $property->type) {
                return $property->name;
            }
        }

        foreach ($this->properties as $property) {
            if ('string' === $property->type) {
                return $property->name;
            }
        }

        return '';
    }

    public function hasAction(string $action): bool
    {
        return in_array($action, $this->actions, true);
    }
}
