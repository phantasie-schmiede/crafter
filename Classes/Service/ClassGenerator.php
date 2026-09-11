<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Service;

use PSBits\Crafter\Data\ClassBlueprint;
use PSBits\Crafter\Data\WriteResult;
use PSBits\Crafter\Exception\CrafterException;
use PSBits\Crafter\Utility\NamingUtility;
use PSBits\Crafter\Utility\PhpUtility;

use function is_array;
use function sprintf;

/**
 * Class ClassGenerator
 *
 * Renders any plain PHP class of the crafter.classTypes catalogue. Services, view helpers, event listeners and
 * backend controllers only differ in configuration, so they all share this generator and the Php/Class skeleton.
 *
 * @package PSBits\Crafter\Service
 */
readonly class ClassGenerator
{
    public const string SKELETON_CLASS       = 'Php/Class';
    public const string SKELETON_CONSTRUCTOR = 'Php/Constructor';

    public function __construct(
        protected ConfigurationService $configurationService,
        protected FileWriter           $fileWriter,
        protected PathResolver         $pathResolver,
        protected SkeletonRenderer     $skeletonRenderer,
    ) {
    }

    /**
     * Promoted constructor arguments for the given dependencies. A readonly class must not repeat the readonly
     * keyword on its properties, so the class keywords decide how the arguments look.
     *
     * @param string[] $dependencies fully qualified class names
     *
     * @return string[]
     */
    public function buildConstructorArguments(array $dependencies, bool $readonlyClass = false): array
    {
        $arguments = [];

        foreach (array_unique($dependencies) as $dependency) {
            $shortName   = PhpUtility::getShortClassName($dependency);
            $arguments[] = sprintf(
                'protected %s%s $%s,',
                true === $readonlyClass ? '' : 'readonly ',
                $shortName,
                NamingUtility::toLowerCamelCase($shortName)
            );
        }

        sort($arguments);

        return $arguments;
    }

    public function generate(ClassBlueprint $blueprint): WriteResult
    {
        return $this->fileWriter->write($this->resolveTargetPath($blueprint), $this->render($blueprint));
    }

    public function getClassName(ClassBlueprint $blueprint): string
    {
        return $blueprint->getClassName(
            (string)($this->getTypeConfiguration($blueprint->type, $blueprint->extensionKey)['suffix'] ?? '')
        );
    }

    public function getNamespace(ClassBlueprint $blueprint): string
    {
        $configuration = $this->getTypeConfiguration($blueprint->type, $blueprint->extensionKey);

        return implode('\\', array_filter([
            $this->pathResolver->resolveNamespace(
                $blueprint->extensionKey,
                (string)$this->configurationService->getValue('defaults.vendor', $blueprint->extensionKey, 'PSBits')
            ),
            trim((string)($configuration['subNamespace'] ?? ''), '\\'),
            str_replace('/', '\\', trim($blueprint->subPath, '/')),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function getTypeConfiguration(string $type, ?string $extensionKey = null): array
    {
        $types = $this->configurationService->getValue('classTypes', $extensionKey, []);

        if (!is_array($types)) {
            $types = [];
        }

        if (!isset($types[$type])) {
            throw new CrafterException(
                sprintf('Unknown class type "%s". Available types: %s', $type, implode(', ', array_keys($types))),
                1757426420
            );
        }

        return (array)$types[$type];
    }

    public function render(ClassBlueprint $blueprint): string
    {
        $extensionKey  = $blueprint->extensionKey;
        $configuration = $this->getTypeConfiguration($blueprint->type, $extensionKey);
        $namespace     = $this->getNamespace($blueprint);
        $parentClass   = trim((string)($configuration['extends'] ?? ''), '\\');
        $keywords      = trim((string)($configuration['keywords'] ?? ''));
        $interfaces    = $this->normalizeClassNames((array)($configuration['implements'] ?? []));
        $traits        = $this->normalizeClassNames((array)($configuration['traits'] ?? []));
        $imports       = array_merge(
            $this->normalizeClassNames((array)($configuration['imports'] ?? [])),
            $this->normalizeClassNames($blueprint->imports),
            $this->normalizeClassNames($blueprint->dependencies),
            $interfaces,
            $traits,
            '' === $parentClass ? [] : [$parentClass]
        );
        $methods = $blueprint->methods;

        if ([] !== $blueprint->dependencies) {
            array_unshift($methods, $this->renderConstructor($blueprint, str_contains($keywords, 'readonly')));
        }

        return $this->skeletonRenderer->render(
            self::SKELETON_CLASS,
            array_merge(
                $this->buildSections($traits, $methods),
                [
                    'classAttributes' => $this->renderAttributes($configuration, $blueprint),
                    'classExtends'    => '' === $parentClass
                        ? ''
                        : ' extends ' . PhpUtility::getShortClassName($parentClass),
                    'classImplements' => [] === $interfaces ? '' : ' implements ' . implode(', ', array_map(
                        static fn(string $interface): string => PhpUtility::getShortClassName($interface),
                        $interfaces
                    )),
                    'classKeywords'   => '' === $keywords ? '' : $keywords . ' ',
                    'className'       => $this->getClassName($blueprint),
                    'imports'         => PhpUtility::renderImports($imports, $namespace),
                    'namespace'       => $namespace,
                ]
            ),
            $extensionKey
        );
    }

    /**
     * Resolves a short dependency name to a fully qualified class name via crafter.dependencies.
     */
    public function resolveDependency(string $name, string $extensionKey): string
    {
        $name = trim($name, '\\');

        if (true === str_contains($name, '\\')) {
            return $name;
        }

        $aliases = (array)$this->configurationService->getValue('dependencies.aliases', $extensionKey, []);

        if (true === isset($aliases[$name])) {
            return trim((string)$aliases[$name], '\\');
        }

        $namespace = $this->pathResolver->resolveNamespace(
            $extensionKey,
            (string)$this->configurationService->getValue('defaults.vendor', $extensionKey, 'PSBits')
        );
        $suffixes = (array)$this->configurationService->getValue('dependencies.suffixes', $extensionKey, []);

        foreach ($suffixes as $suffix => $subNamespace) {
            if (true === str_ends_with($name, (string)$suffix)) {
                return $namespace . '\\' . trim((string)$subNamespace, '\\') . '\\' . $name;
            }
        }

        throw new CrafterException(
            sprintf(
                'Dependency "%s" cannot be resolved. Give a fully qualified class name or use one of these suffixes:'
                . ' %s',
                $name,
                implode(', ', array_keys($suffixes))
            ),
            1757426421
        );
    }

    public function resolveTargetPath(ClassBlueprint $blueprint): string
    {
        $configuration = $this->getTypeConfiguration($blueprint->type, $blueprint->extensionKey);

        return $this->pathResolver->resolveTargetPath(
            (string)($configuration['pathType'] ?? $blueprint->type),
            $blueprint->extensionKey,
            [
                'lowerName' => $blueprint->getLowerCamelCaseName(),
                'name'      => $blueprint->name,
                'path'      => trim($blueprint->subPath, '/'),
            ]
        );
    }

    /**
     * The class body sections are separated by a blank line. Because an empty block placeholder removes its whole
     * line, only the sections that carry content take part in the separation.
     *
     * @param string[] $traits  fully qualified trait names
     * @param string[] $methods rendered methods
     *
     * @return array<string, string>
     */
    private function buildSections(array $traits, array $methods): array
    {
        $sections = [
            'traits'     => implode("\n", array_map(
                static fn(string $trait): string => '    use ' . PhpUtility::getShortClassName($trait) . ';',
                $traits
            )),
            'constants'  => '',
            'properties' => '',
            'methods'    => implode("\n\n", array_map(
                static fn(string $method): string => self::indent($method),
                array_values(array_filter($methods))
            )),
        ];
        $isFirst = true;

        foreach ($sections as $name => $content) {
            if ('' === $content) {
                continue;
            }

            if (false === $isFirst) {
                $sections[$name] = "\n" . $content;
            }

            $isFirst = false;
        }

        return $sections;
    }

    private static function indent(string $value): string
    {
        return implode("\n", array_map(
            static fn(string $line): string => '' === trim($line) ? '' : '    ' . $line,
            explode("\n", rtrim($value))
        ));
    }

    /**
     * @param array<int, mixed> $classNames
     *
     * @return string[]
     */
    private function normalizeClassNames(array $classNames): array
    {
        return array_values(array_filter(array_map(
            static fn(mixed $className): string => trim((string)$className, '\\'),
            $classNames
        )));
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function renderAttributes(array $configuration, ClassBlueprint $blueprint): string
    {
        return implode("\n", array_map(
            static fn(mixed $attribute): string => '#[' . str_replace(
                [
                    '{extensionKey}',
                    '{lowerName}',
                    '{name}',
                ],
                [
                    $blueprint->extensionKey,
                    $blueprint->getLowerCamelCaseName(),
                    $blueprint->name,
                ],
                (string)$attribute
            ) . ']',
            (array)($configuration['attributes'] ?? [])
        ));
    }

    private function renderConstructor(ClassBlueprint $blueprint, bool $readonlyClass): string
    {
        $arguments = $this->buildConstructorArguments($blueprint->dependencies, $readonlyClass);

        if ([] === $arguments) {
            return '';
        }

        return rtrim($this->skeletonRenderer->render(
            self::SKELETON_CONSTRUCTOR,
            ['constructorArguments' => implode("\n", PhpUtility::alignArguments($arguments))],
            $blueprint->extensionKey
        ));
    }
}
