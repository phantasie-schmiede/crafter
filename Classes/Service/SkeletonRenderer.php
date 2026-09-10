<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Service;

use PSBits\Crafter\Event\AfterSkeletonRenderEvent;
use PSBits\Crafter\Event\BeforeSkeletonRenderEvent;
use PSBits\Crafter\Exception\CrafterException;
use PSBits\Crafter\Utility\NamingUtility;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Class SkeletonRenderer
 *
 * Renders `.stub` files by replacing `{{ placeholder }}` markers. Stubs contain no logic on purpose: loops and
 * ordering happen in PHP, every repeatable block is a stub of its own. This keeps stubs readable and overridable.
 *
 * A placeholder that sits alone on a line is treated as a block:
 * - its indentation is applied to every line of the inserted value
 * - if the value is empty, the whole line is removed
 *
 * @package PSBits\Crafter\Service
 */
class SkeletonRenderer
{
    public const string BLOCK_PATTERN        = '/^([ \t]*)\{\{\s*([\w.]+)\s*}}[ \t]*(\r?\n|$)/m';
    public const string FILE_HEADER_SKELETON = 'Php/FileHeader';
    public const string INLINE_PATTERN       = '/\{\{\s*([\w.]+)\s*}}/';

    private bool $renderingFileHeader = false;

    public function __construct(
        protected readonly ConfigurationService     $configurationService,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly PathResolver             $pathResolver,
    ) {
    }

    /**
     * All variables that are available in every stub without being passed explicitly.
     *
     * @return array<string, string>
     */
    public function getGlobalVariables(?string $extensionKey = null): array
    {
        $variables = [];

        foreach ($this->configurationService->getValue('defaults', $extensionKey, []) as $key => $value) {
            if (is_scalar($value)) {
                $variables[$key] = (string)$value;
            }
        }

        foreach ($this->configurationService->getValue('variables', $extensionKey, []) as $key => $value) {
            if (is_scalar($value)) {
                $variables[$key] = (string)$value;
            }
        }

        $variables['extensionKey']  = $extensionKey ?? '';
        $variables['extensionName'] = '';
        $variables['packageTitle']  = $variables['vendor'] ?? '';
        $variables['year']          = date('Y');

        if (null !== $extensionKey) {
            $extensionName              = NamingUtility::toUpperCamelCase($extensionKey);
            $variables['extensionName'] = $extensionName;
            $variables['packageTitle']  = trim(
                ($variables['vendor'] ?? '') . ' ' . preg_replace('/(?<!^)[A-Z]/', ' $0', $extensionName)
            );
        }

        // Guarded against recursion: the file header stub itself must not receive a rendered file header.
        if (false === $this->renderingFileHeader) {
            $this->renderingFileHeader = true;

            try {
                $variables['fileHeader'] = rtrim(
                    $this->render(self::FILE_HEADER_SKELETON, $variables, $extensionKey)
                );
            } finally {
                $this->renderingFileHeader = false;
            }
        }

        return $variables;
    }

    /**
     * @param array<string, string> $variables
     */
    public function render(string $identifier, array $variables = [], ?string $extensionKey = null): string
    {
        $beforeEvent = new BeforeSkeletonRenderEvent(
            $identifier,
            array_merge($this->getGlobalVariables($extensionKey), $variables),
            $extensionKey
        );
        $this->eventDispatcher->dispatch($beforeEvent);
        $identifier = $beforeEvent->getIdentifier();
        $variables  = $beforeEvent->getVariables();
        $skeleton   = file_get_contents($this->pathResolver->resolveSkeleton($identifier, $extensionKey));

        if (false === $skeleton) {
            throw new CrafterException(sprintf('Skeleton "%s" could not be read.', $identifier), 1757426406);
        }

        $afterEvent = new AfterSkeletonRenderEvent(
            $identifier,
            $this->replacePlaceholders($skeleton, $variables, $identifier),
            $variables,
            $extensionKey
        );
        $this->eventDispatcher->dispatch($afterEvent);

        return $afterEvent->getContent();
    }

    /**
     * @param array<string, string> $variables
     */
    private function replacePlaceholders(string $skeleton, array $variables, string $identifier): string
    {
        $skeleton = preg_replace_callback(
            self::BLOCK_PATTERN,
            function(array $matches) use ($variables, $identifier): string {
                $value = $this->resolveValue($matches[2], $variables, $identifier);

                if ('' === trim($value)) {
                    return '';
                }

                return $this->indent($value, $matches[1]) . $matches[3];
            },
            $skeleton
        );

        return preg_replace_callback(
            self::INLINE_PATTERN,
            fn(array $matches): string => $this->resolveValue($matches[1], $variables, $identifier),
            (string)$skeleton
        );
    }

    private function indent(string $value, string $indentation): string
    {
        if ('' === $indentation) {
            return rtrim($value, "\r\n");
        }

        $lines = preg_split('/\r?\n/', rtrim($value, "\r\n")) ?: [];

        return implode("\n", array_map(
            static fn(string $line): string => '' === trim($line) ? '' : $indentation . $line,
            $lines
        ));
    }

    /**
     * @param array<string, string> $variables
     */
    private function resolveValue(string $name, array $variables, string $identifier): string
    {
        if (array_key_exists($name, $variables)) {
            return (string)$variables[$name];
        }

        // Any configuration value can be addressed via dot path, e.g. {{ paths.model }}.
        $configurationValue = $this->configurationService->getValue(
            $name,
            '' === ($variables['extensionKey'] ?? '') ? null : $variables['extensionKey']
        );

        if (is_scalar($configurationValue)) {
            return (string)$configurationValue;
        }

        $availableNames = array_keys($variables);
        sort($availableNames);

        throw new CrafterException(
            sprintf(
                'Unknown placeholder "{{ %s }}" in skeleton "%s". Available placeholders: %s',
                $name,
                $identifier,
                implode(', ', $availableNames)
            ),
            1757426407
        );
    }
}
