<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Service;

use PSBits\Crafter\Utility\PhpUtility;

use function count;
use function strlen;

/**
 * Class CodeInjector
 *
 * Adds entries to already existing classes. The file is edited line by line instead of being rebuilt from a parser,
 * so that formatting, comments and manual changes of the developer survive untouched.
 *
 * @package PSBits\Crafter\Service
 */
class CodeInjector
{
    public const string CONSTRUCTOR_PATTERN = '/^\s*public function __construct\(\s*\)/';
    public const string MAIN_MODULE_START   = '$mainModuleKey = ';
    public const string MODULE_START        = '$this->addModule(';
    public const string PLUGIN_NAME_PATTERN = "/(?:name|key)\s*:\s*.*'([^']+)'/";
    public const string PLUGIN_START        = '$this->addPlugin(';

    /**
     * Inserts a block into the constructor of a class, keeping the existing blocks of the same kind sorted.
     *
     * @param string[] $imports fully qualified class names required by the block
     *
     * @return string|null the modified source or null if the block is already present
     */
    public function addConstructorBlock(
        string  $source,
        string  $block,
        string  $blockStart,
        string  $sortKey,
        array   $imports = [],
        ?string $insertBeforeBlockStart = null,
    ): ?string {
        $lines = explode("\n", $source);
        $body  = $this->locateConstructorBody($lines);

        if (null === $body) {
            $lines = $this->createConstructor($lines);
            $body  = $this->locateConstructorBody($lines);
        }

        if (null === $body) {
            return null;
        }

        $blocks = $this->locateBlocks($lines, $body['start'], $body['end'], $blockStart);

        foreach ($blocks as $existing) {
            if ($existing['sortKey'] === $sortKey) {
                return null;
            }
        }

        $insertAt = $body['end'];
        $prefix   = ['']; // blank line between the existing content and the new block

        foreach ($blocks as $existing) {
            if ($existing['sortKey'] > $sortKey) {
                $insertAt = $existing['start'];
                $prefix   = [];

                break;
            }
        }

        // Blocks of a different kind may have to stay behind the new one, e.g. plugins behind modules.
        if ([] !== $prefix && null !== $insertBeforeBlockStart) {
            $following = $this->locateBlocks($lines, $body['start'], $body['end'], $insertBeforeBlockStart);

            if ([] !== $following) {
                $insertAt = $following[0]['start'];
                $prefix   = [];
            }
        }

        $newLines = explode("\n", rtrim($this->indent($block, '        ')));

        if ([] === $prefix && $insertAt < $body['end']) {
            $newLines[] = '';
        }

        array_splice($lines, $insertAt, 0, array_merge($prefix, $newLines));

        return $this->addImports(implode("\n", $lines), $imports);
    }

    /**
     * @param string[] $imports fully qualified class names
     */
    public function addImports(string $source, array $imports): string
    {
        if ([] === $imports) {
            return $source;
        }

        $lines    = explode("\n", $source);
        $existing = [];
        $first    = null;
        $last     = null;

        foreach ($lines as $index => $line) {
            if (1 !== preg_match('/^use ([^;]+);$/', $line, $match)) {
                continue;
            }

            $existing[] = $match[1];
            $first ??= $index;
            $last = $index;
        }

        $merged = array_unique(array_merge($existing, array_map(
            static fn(string $import): string => trim($import, '\\'),
            $imports
        )));

        if (count($merged) === count($existing)) {
            return $source;
        }

        $block = explode("\n", PhpUtility::renderImports($merged));

        if (null === $first || null === $last) {
            return $source;
        }

        array_splice($lines, $first, $last - $first + 1, $block);

        return implode("\n", $lines);
    }

    /**
     * @param string[] $lines
     *
     * @return string[]
     */
    private function createConstructor(array $lines): array
    {
        foreach ($lines as $index => $line) {
            if (1 !== preg_match('/^(?:readonly )?(?:final )?class \w+/', $line)) {
                continue;
            }

            // The class body starts on the line with the opening brace right below the class declaration.
            array_splice($lines, $index + 2, 0, [
                '    public function __construct()',
                '    {',
                '        parent::__construct();',
                '    }',
            ]);

            return $lines;
        }

        return $lines;
    }

    private function indent(string $value, string $indentation): string
    {
        return implode("\n", array_map(
            static fn(string $line): string => '' === $line ? $line : $indentation . $line,
            explode("\n", $value)
        ));
    }

    /**
     * @param string[] $lines
     *
     * @return array<int, array{end: int, sortKey: string, start: int}>
     */
    private function locateBlocks(array $lines, int $start, int $end, string $blockStart): array
    {
        $blocks = [];

        for ($index = $start; $index < $end; ++$index) {
            if (!str_contains($lines[$index], $blockStart)) {
                continue;
            }

            $blockEnd = $this->locateBlockEnd($lines, $index, $end);
            $sortKey  = '';

            for ($inner = $index; $inner <= $blockEnd; ++$inner) {
                if (1 === preg_match(self::PLUGIN_NAME_PATTERN, $lines[$inner], $match)) {
                    $sortKey = $match[1];

                    break;
                }
            }

            $blocks[] = [
                'end'     => $blockEnd,
                'sortKey' => $sortKey,
                'start'   => $index,
            ];
            $index = $blockEnd;
        }

        return $blocks;
    }

    /**
     * @param string[] $lines
     */
    private function locateBlockEnd(array $lines, int $start, int $end): int
    {
        $indentation = strlen($lines[$start]) - strlen(ltrim($lines[$start]));

        for ($index = $start + 1; $index < $end; ++$index) {
            if (str_repeat(' ', $indentation) . ');' === rtrim($lines[$index])) {
                return $index;
            }
        }

        return $start;
    }

    /**
     * @param string[] $lines
     *
     * @return array{end: int, start: int}|null the first and the line after the last line of the constructor body
     */
    private function locateConstructorBody(array $lines): ?array
    {
        foreach ($lines as $index => $line) {
            if (1 !== preg_match(self::CONSTRUCTOR_PATTERN, $line)) {
                continue;
            }

            $indentation = str_repeat(' ', strlen($line) - strlen(ltrim($line)));

            for ($end = $index + 2; $end < count($lines); ++$end) {
                if ($indentation . '}' === rtrim($lines[$end])) {
                    return [
                        'end'   => $end,
                        'start' => $index + 2,
                    ];
                }
            }
        }

        return null;
    }
}
