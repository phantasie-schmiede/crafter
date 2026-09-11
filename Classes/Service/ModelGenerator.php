<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Service;

use PSBits\Crafter\Data\ModelBlueprint;
use PSBits\Crafter\Data\PropertyDefinition;
use PSBits\Crafter\Data\WriteResult;
use PSBits\Crafter\Utility\NamingUtility;
use PSBits\Crafter\Utility\PhpUtility;

use function is_array;
use function sprintf;
use function strlen;

/**
 * Class ModelGenerator
 *
 * Assembles a domain model from the small stubs under Php/. Ordering and repetition happen here, the stubs stay
 * free of logic and therefore easy to override.
 *
 * @package PSBits\Crafter\Service
 */
readonly class ModelGenerator
{
    public const string ENUM_SUB_NAMESPACE       = 'Domain\Enum';
    public const string MODEL_SUB_NAMESPACE      = 'Domain\Model';
    public const string SKELETON_GETTER          = 'Php/Getter';
    public const string SKELETON_INITIALIZE      = 'Php/InitializeObject';
    public const string SKELETON_MODEL           = 'Php/Model';
    public const string SKELETON_MODEL_CONSTRUCT = 'Php/ModelConstructor';
    public const string SKELETON_PROPERTY        = 'Php/Property';
    public const string SKELETON_STORAGE_METHOD  = 'Php/ObjectStorageMethod';
    public const string SKELETON_SETTER          = 'Php/Setter';

    public function __construct(
        protected ConfigurationService $configurationService,
        protected FieldTypeRegistry    $fieldTypeRegistry,
        protected FileWriter           $fileWriter,
        protected PathResolver         $pathResolver,
        protected SkeletonRenderer     $skeletonRenderer,
    ) {
    }

    public function generate(ModelBlueprint $blueprint): WriteResult
    {
        return $this->fileWriter->write(
            $this->pathResolver->resolveTargetPath('model', $blueprint->extensionKey, ['name' => $blueprint->name]),
            $this->render($blueprint)
        );
    }

    public function render(ModelBlueprint $blueprint): string
    {
        $extensionKey    = $blueprint->extensionKey;
        $modelNamespace  = $this->getModelNamespace($extensionKey);
        $parentClassName = (string)$this->configurationService->getValue(
            'defaults.modelExtends',
            $extensionKey,
            'TYPO3\CMS\Extbase\DomainObject\AbstractEntity'
        );
        $imports      = [$parentClassName];
        $methods      = [];
        $properties   = [];
        $storageNames = [];

        foreach ($this->sortProperties($blueprint->properties) as $property) {
            $context      = $this->buildContext($property, $blueprint);
            $imports      = array_merge($imports, $context['imports']);
            $properties[] = $this->renderProperty($property, $context, $extensionKey);
            $methods += $this->renderMethods($property, $context, $extensionKey);

            if (true === $context['objectStorage']) {
                $storageNames[] = $property->name;
            }
        }

        $constructor = '';

        if ([] !== $storageNames) {
            $constructor = rtrim(
                $this->skeletonRenderer->render(self::SKELETON_MODEL_CONSTRUCT, [], $extensionKey)
            );
            $methods['initializeObject'] = rtrim($this->skeletonRenderer->render(
                self::SKELETON_INITIALIZE,
                ['methodBody' => $this->renderInitializeObjectBody($storageNames)],
                $extensionKey
            ));
        }

        ksort($methods);
        $classAttributes = $this->renderClassAttributes($blueprint, $extensionKey, $imports);
        $sections        = array_values(array_filter(array_merge(
            [implode("\n\n", $properties), $constructor],
            array_values($methods)
        )));

        return $this->skeletonRenderer->render(
            self::SKELETON_MODEL,
            [
                'classAttributes' => $classAttributes,
                'classBody'       => implode("\n\n", $sections),
                'className'       => $blueprint->name,
                'imports'         => PhpUtility::renderImports($imports, $modelNamespace),
                'modelNamespace'  => $modelNamespace,
                'parentClass'     => PhpUtility::getShortClassName($parentClassName),
            ],
            $extensionKey
        );
    }

    /**
     * Everything a single property needs, resolved once and reused by property and method rendering.
     *
     * @return array{
     *     attributes: string[],
     *     defaultValue: string|null,
     *     docBlockType: string,
     *     imports: string[],
     *     itemClass: string,
     *     objectStorage: bool,
     *     phpType: string
     * }
     */
    private function buildContext(PropertyDefinition $property, ModelBlueprint $blueprint): array
    {
        $extensionKey  = $blueprint->extensionKey;
        $registry      = $this->fieldTypeRegistry;
        $type          = $property->type;
        $replacements  = $this->buildReplacements($property, $blueprint);
        $attributes    = $this->replace($registry->getAttributes($type, $extensionKey), $replacements);
        $imports       = $this->replace($registry->getImports($type, $extensionKey), $replacements);
        $objectStorage = $registry->isObjectStorage($type, $extensionKey);

        if (true === $property->required) {
            $attributes = array_merge($attributes, $registry->getRequiredAttributes($extensionKey));
            $imports    = array_merge($imports, $registry->getRequiredImports($extensionKey));
        }

        $linkedClassType = $registry->getLinkedClassType($type, $extensionKey);

        if ('' !== $linkedClassType && '' !== $property->linkedClass) {
            $imports[] = $this->getLinkedClassName($property->linkedClass, $linkedClassType, $extensionKey);
        }

        sort($attributes);
        $docBlockType = $this->extractGenericType(
            $this->replace([$registry->getDocBlock($type, $extensionKey)], $replacements)[0]
        );

        return [
            'attributes'    => $attributes,
            'defaultValue'  => $registry->getDefaultValue($type, $extensionKey),
            'docBlockType'  => $docBlockType,
            'imports'       => $imports,
            'itemClass'     => $this->extractItemClass($docBlockType),
            'objectStorage' => $objectStorage,
            'phpType'       => $this->replace(
                [$registry->getPhpType($type, $extensionKey)],
                $replacements
            )[0],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildReplacements(PropertyDefinition $property, ModelBlueprint $blueprint): array
    {
        return [
            '{foreignField}' => '' !== $property->foreignField
                ? $property->foreignField
                : $blueprint->getLowerCamelCaseName(),
            // Only the short name is rendered, the fully qualified name is added as an import.
            '{linkedClass}'  => PhpUtility::getShortClassName($property->linkedClass),
            '{mmTable}'      => $blueprint->getTableName() . '_' . $property->getColumnName() . '_mm',
            '{propertyName}' => $property->name,
            '{tableName}'    => $blueprint->getTableName(),
        ];
    }

    /**
     * Reads the type out of a doc block line, example: '@var ObjectStorage<Contact>' => 'ObjectStorage<Contact>'
     */
    private function extractGenericType(string $docBlock): string
    {
        return trim(preg_replace('/^@var\s+/', '', trim($docBlock)) ?? '');
    }

    /**
     * Example: 'ObjectStorage<Contact>' => 'Contact'
     */
    private function extractItemClass(string $docBlockType): string
    {
        return 1 === preg_match('/<\s*([^>]+)\s*>/', $docBlockType, $matches) ? trim($matches[1]) : '';
    }

    private function getLinkedClassName(string $linkedClass, string $linkedClassType, string $extensionKey): string
    {
        if (str_contains($linkedClass, '\\')) {
            return $linkedClass;
        }

        $subNamespace = FieldTypeRegistry::LINKED_CLASS_ENUM === $linkedClassType
            ? self::ENUM_SUB_NAMESPACE
            : self::MODEL_SUB_NAMESPACE;

        return $this->getNamespace($extensionKey) . '\\' . $subNamespace . '\\' . $linkedClass;
    }

    private function getModelNamespace(string $extensionKey): string
    {
        return $this->getNamespace($extensionKey) . '\\' . self::MODEL_SUB_NAMESPACE;
    }

    private function getNamespace(string $extensionKey): string
    {
        return $this->pathResolver->resolveNamespace(
            $extensionKey,
            (string)$this->configurationService->getValue('defaults.vendor', $extensionKey, '')
        );
    }

    /**
     * @param string[] $values
     * @param array<string, string> $replacements
     *
     * @return string[]
     */
    private function replace(array $values, array $replacements): array
    {
        return array_map(
            static fn(string $value): string => str_replace(
                array_keys($replacements),
                array_values($replacements),
                $value
            ),
            $values
        );
    }

    /**
     * @param string[] $imports
     */
    private function renderClassAttributes(
        ModelBlueprint $blueprint,
        string         $extensionKey,
        array          &$imports,
    ): string {
        $configuration = $this->configurationService->getValue('modelCtrl', $extensionKey, []);

        if (!is_array($configuration) || [] === ($configuration['attributes'] ?? [])) {
            return '';
        }

        $labelProperty = $this->determineLabelProperty($blueprint, $extensionKey);

        if ('' === $labelProperty) {
            return '';
        }

        $imports = array_merge($imports, array_map(
            static fn(mixed $import): string => (string)$import,
            (array)($configuration['imports'] ?? [])
        ));

        return implode("\n", array_map(
            static fn(mixed $attribute): string => '#[' . str_replace(
                '{labelProperty}',
                $labelProperty,
                (string)$attribute
            ) . ']',
            (array)$configuration['attributes']
        ));
    }

    private function determineLabelProperty(ModelBlueprint $blueprint, string $extensionKey): string
    {
        $fallback = '';

        foreach ($this->sortProperties($blueprint->properties) as $property) {
            if ('string' !== $this->fieldTypeRegistry->getPhpType($property->type, $extensionKey)) {
                continue;
            }

            if (true === $property->required) {
                return $property->name;
            }

            if ('' === $fallback) {
                $fallback = $property->name;
            }
        }

        return $fallback;
    }

    /**
     * @param string[] $storageNames
     */
    private function renderInitializeObjectBody(array $storageNames): string
    {
        $nameLength = 0;

        foreach ($storageNames as $name) {
            $nameLength = max($nameLength, strlen($name));
        }

        return implode("\n", array_map(
            static fn(string $name): string => sprintf(
                '$this->%s%s = new ObjectStorage();',
                $name,
                str_repeat(' ', $nameLength - strlen($name))
            ),
            $storageNames
        ));
    }

    /**
     * @param array{
     *     attributes: string[],
     *     defaultValue: string|null,
     *     docBlockType: string,
     *     imports: string[],
     *     itemClass: string,
     *     objectStorage: bool,
     *     phpType: string
     * } $context
     *
     * @return array<string, string>
     */
    private function renderMethods(PropertyDefinition $property, array $context, string $extensionKey): array
    {
        $fluent = true === $this->configurationService->getValue(
            'defaults.fluentSetters',
            $extensionKey,
            true
        );
        $methodReturnType = $fluent ? 'self' : 'void';
        $getterName       = ('bool' === $context['phpType'] ? 'is' : 'get') . $property->getMethodSuffix();
        $setterName       = 'set' . $property->getMethodSuffix();
        $setterBody       = sprintf('$this->%s = $%s;', $property->name, $property->name);
        $methods          = [
            $getterName => rtrim($this->skeletonRenderer->render(
                self::SKELETON_GETTER,
                [
                    'methodDocBlock' => '' !== $context['docBlockType']
                        ? sprintf('/** @return %s */', $context['docBlockType'])
                        : '',
                    'methodName'     => $getterName,
                    'propertyName'   => $property->name,
                    'propertyType'   => $context['phpType'],
                ],
                $extensionKey
            )),
            $setterName => rtrim($this->skeletonRenderer->render(
                self::SKELETON_SETTER,
                [
                    'methodBody'       => $fluent ? $setterBody . "\n\nreturn \$this;" : $setterBody,
                    'methodDocBlock'   => '' !== $context['docBlockType']
                        ? sprintf('/** @param %s $%s */', $context['docBlockType'], $property->name)
                        : '',
                    'methodName'       => $setterName,
                    'methodReturnType' => $methodReturnType,
                    'propertyName'     => $property->name,
                    'propertyType'     => $context['phpType'],
                ],
                $extensionKey
            )),
        ];

        if (false === $context['objectStorage'] || '' === $context['itemClass']) {
            return $methods;
        }

        $parameterName = NamingUtility::toLowerCamelCase($context['itemClass']);

        foreach (['add' => 'attach', 'remove' => 'detach'] as $prefix => $storageMethod) {
            $methodName = $prefix . $property->getSingularMethodSuffix();
            $body       = sprintf(
                '$this->%s->%s($%s);',
                $property->name,
                $storageMethod,
                $parameterName
            );
            $methods[$methodName] = rtrim($this->skeletonRenderer->render(
                self::SKELETON_STORAGE_METHOD,
                [
                    'linkedClass'      => $context['itemClass'],
                    'methodBody'       => $fluent ? $body . "\n\nreturn \$this;" : $body,
                    'methodDocBlock'   => '',
                    'methodName'       => $methodName,
                    'methodReturnType' => $methodReturnType,
                    'parameterName'    => $parameterName,
                ],
                $extensionKey
            ));
        }

        return $methods;
    }

    /**
     * @param array{
     *     attributes: string[],
     *     defaultValue: string|null,
     *     docBlockType: string,
     *     imports: string[],
     *     itemClass: string,
     *     objectStorage: bool,
     *     phpType: string
     * } $context
     */
    private function renderProperty(PropertyDefinition $property, array $context, string $extensionKey): string
    {
        return rtrim($this->skeletonRenderer->render(
            self::SKELETON_PROPERTY,
            [
                'propertyAttributes' => implode("\n", array_map(
                    static fn(string $attribute): string => '#[' . $attribute . ']',
                    $context['attributes']
                )),
                'propertyDefault'    => null !== $context['defaultValue'] ? ' = ' . $context['defaultValue'] : '',
                'propertyDocBlock'   => '' !== $context['docBlockType']
                    ? sprintf('/** @var %s */', $context['docBlockType'])
                    : '',
                'propertyName'       => $property->name,
                'propertyType'       => $context['phpType'],
            ],
            $extensionKey
        ));
    }

    /**
     * @param PropertyDefinition[] $properties
     *
     * @return PropertyDefinition[]
     */
    private function sortProperties(array $properties): array
    {
        usort(
            $properties,
            static fn(PropertyDefinition $a, PropertyDefinition $b): int => strcmp($a->name, $b->name)
        );

        return $properties;
    }
}
