<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Service;

use PSBits\Crafter\Exception\CrafterException;

use function array_key_exists;
use function is_array;
use function sprintf;

/**
 * Class FieldTypeRegistry
 *
 * Read access to the configurable `fieldTypes` map. Keeping the field descriptions in configuration instead of PHP
 * allows projects to add own property types without extending any class.
 *
 * @package PSBits\Crafter\Service
 */
readonly class FieldTypeRegistry
{
    public const string LINKED_CLASS_ENUM  = 'enum';
    public const string LINKED_CLASS_MODEL = 'model';

    public function __construct(
        protected ConfigurationService $configurationService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $type, ?string $extensionKey = null): array
    {
        $fieldTypes = $this->getAll($extensionKey);

        if (!isset($fieldTypes[$type])) {
            throw new CrafterException(
                sprintf(
                    'Unknown field type "%s". Available types: %s',
                    $type,
                    implode(', ', array_keys($fieldTypes))
                ),
                1757426411
            );
        }

        return $fieldTypes[$type];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAll(?string $extensionKey = null): array
    {
        $fieldTypes = $this->configurationService->getValue('fieldTypes', $extensionKey, []);

        if (!is_array($fieldTypes) || [] === $fieldTypes) {
            throw new CrafterException('No fieldTypes configured.', 1757426412);
        }

        ksort($fieldTypes);

        return $fieldTypes;
    }

    /**
     * @return string[]
     */
    public function getAttributes(string $type, ?string $extensionKey = null): array
    {
        return $this->getList($this->get($type, $extensionKey), 'attributes');
    }

    public function getDefaultValue(string $type, ?string $extensionKey = null): ?string
    {
        $fieldType = $this->get($type, $extensionKey);

        return array_key_exists('defaultValue', $fieldType) ? (string)$fieldType['defaultValue'] : null;
    }

    public function getDocBlock(string $type, ?string $extensionKey = null): string
    {
        return (string)($this->get($type, $extensionKey)['docBlock'] ?? '');
    }

    /**
     * @return string[]
     */
    public function getImports(string $type, ?string $extensionKey = null): array
    {
        return $this->getList($this->get($type, $extensionKey), 'imports');
    }

    /**
     * Returns 'enum', 'model' or an empty string if the type does not reference another class.
     */
    public function getLinkedClassType(string $type, ?string $extensionKey = null): string
    {
        return (string)($this->get($type, $extensionKey)['linkedClass'] ?? '');
    }

    /**
     * @return string[]
     */
    public function getNames(?string $extensionKey = null): array
    {
        return array_keys($this->getAll($extensionKey));
    }

    public function getPhpType(string $type, ?string $extensionKey = null): string
    {
        $phpType = (string)($this->get($type, $extensionKey)['phpType'] ?? '');

        if ('' === $phpType) {
            throw new CrafterException(
                sprintf('Field type "%s" does not define a phpType.', $type),
                1757426413
            );
        }

        return $phpType;
    }

    /**
     * @return string[]
     */
    public function getRequiredAttributes(?string $extensionKey = null): array
    {
        return $this->getList($this->getRequiredProperty($extensionKey), 'attributes');
    }

    /**
     * @return string[]
     */
    public function getRequiredImports(?string $extensionKey = null): array
    {
        return $this->getList($this->getRequiredProperty($extensionKey), 'imports');
    }

    public function isObjectStorage(string $type, ?string $extensionKey = null): bool
    {
        return true === ($this->get($type, $extensionKey)['objectStorage'] ?? false);
    }

    /**
     * @param array<string, mixed> $configuration
     *
     * @return string[]
     */
    private function getList(array $configuration, string $key): array
    {
        $values = $configuration[$key] ?? [];

        if (!is_array($values)) {
            return [];
        }

        return array_map(static fn(mixed $value): string => (string)$value, array_values($values));
    }

    /**
     * @return array<string, mixed>
     */
    private function getRequiredProperty(?string $extensionKey): array
    {
        $configuration = $this->configurationService->getValue('requiredProperty', $extensionKey, []);

        return is_array($configuration) ? $configuration : [];
    }
}
