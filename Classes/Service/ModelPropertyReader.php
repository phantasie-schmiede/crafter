<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Service;

use PSBits\Crafter\Data\PropertyDefinition;

/**
 * Class ModelPropertyReader
 *
 * Recovers the property definitions of an already generated domain model by reading its source file. The file is
 * parsed instead of reflected, because a freshly created extension is not autoloadable yet.
 *
 * @package PSBits\Crafter\Service
 */
readonly class ModelPropertyReader
{
    public const string PROPERTY_PATTERN = '/((?:^[ \t]*#\[.*\]\r?\n)*)^[ \t]*protected[ \t]+\??[\\\\\w|]+[ \t]+\$(\w+)/m';

    public function __construct(
        protected FieldTypeRegistry $fieldTypeRegistry,
    ) {
    }

    /**
     * @return PropertyDefinition[]
     */
    public function read(string $filePath, ?string $extensionKey = null): array
    {
        if (false === is_file($filePath)) {
            return [];
        }

        $source = (string)file_get_contents($filePath);

        if (1 !== preg_match_all(self::PROPERTY_PATTERN, $source, $matches, PREG_SET_ORDER) && [] === $matches) {
            return [];
        }

        $patterns   = $this->buildTypePatterns($extensionKey);
        $properties = [];

        foreach ($matches as $match) {
            $attributes   = trim($match[1]);
            $properties[] = new PropertyDefinition(
                name    : $match[2],
                type    : $this->matchType($attributes, $patterns),
                required: str_contains($attributes, 'required: true')
            );
        }

        return $properties;
    }

    /**
     * Turns the configured attribute templates into regular expressions, so that variants of the same attribute,
     * e.g. Number(format: NumberFormat::decimal) and a plain Number, stay distinguishable. All attributes of a type
     * have to be present for it to match.
     *
     * @return array<string, string[]>
     */
    private function buildTypePatterns(?string $extensionKey): array
    {
        $patterns = [];

        foreach ($this->fieldTypeRegistry->getAll($extensionKey) as $type => $configuration) {
            $attributes = (array)($configuration['attributes'] ?? []);

            if ([] === $attributes) {
                continue;
            }

            foreach ($attributes as $attribute) {
                $patterns[$type][] = '/' . $this->buildAttributePattern((string)$attribute) . '/';
            }
        }

        return $patterns;
    }

    /**
     * Turns an attribute template into a regular expression. Placeholders such as {linkedClass} become wildcards,
     * everything else is matched literally.
     */
    private function buildAttributePattern(string $attribute): string
    {
        $parts = preg_split('/\{\w+}/', '#[' . $attribute . ']') ?: [];

        return implode('.*', array_map(
            static fn(string $part): string => preg_quote($part, '/'),
            $parts
        ));
    }

    /**
     * @param array<string, string[]> $patterns
     */
    private function matchType(string $attributes, array $patterns): string
    {
        foreach ($patterns as $type => $typePatterns) {
            foreach ($typePatterns as $pattern) {
                if (1 !== preg_match($pattern, $attributes)) {
                    continue 2;
                }
            }

            return $type;
        }

        return 'string';
    }
}
