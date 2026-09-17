<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Service;

use PSBits\Crafter\Data\ContentProfile;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function is_dir;
use function pathinfo;

/**
 * Class ExtensionProfileService
 *
 * Builds a content profile of an extension - either from the files that are about to be generated or from a scan
 * of an existing extension directory. The profile is the input for the tool selection of the quality group, so an
 * extension with TypoScript files gets the TypoScript linter and one without PHP gets none of the PHP tools.
 *
 * The `Build/` directory is intentionally excluded: the quality files themselves carry the very extensions that
 * would otherwise turn every tool on automatically.
 *
 * @package PSBits\Crafter\Service
 */
class ExtensionProfileService
{
    public const array EXTENSION_SIGNALS = [
        'html'       => 'html',
        'php'        => 'php',
        'tsconfig'   => 'typoscript',
        'typoscript' => 'typoscript',
        'xlf'        => 'xlf',
        'xml'        => 'xml',
        'yaml'       => 'yaml',
        'yml'        => 'yaml',
    ];

    /** @var string[] */
    private const array EXCLUDED_DIRECTORIES = [
        '.Build',
        '.git',
        '.idea',
        'Build',
        'node_modules',
        'Resources/Public',
        'vendor',
    ];

    /**
     * @param string[] $relativePaths
     */
    public function fromPlannedFiles(array $relativePaths): ContentProfile
    {
        $signals = [];

        foreach ($relativePaths as $relativePath) {
            $signal = $this->detectSignal((string)$relativePath);

            if (null !== $signal) {
                $signals[$signal] = true;
            }
        }

        return new ContentProfile($signals);
    }

    public function fromDirectory(string $extensionPath): ContentProfile
    {
        $signals = [];

        if (!is_dir($extensionPath)) {
            return new ContentProfile($signals);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extensionPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($this->isExcluded($file->getPathname(), $extensionPath)) {
                continue;
            }

            $signal = $this->detectSignal($file->getFilename());

            if (null !== $signal) {
                $signals[$signal] = true;
            }
        }

        return new ContentProfile($signals);
    }

    private function detectSignal(string $path): ?string
    {
        if ($this->isExcluded($path, '')) {
            return null;
        }

        $signal = self::EXTENSION_SIGNALS[strtolower((string)pathinfo($path, PATHINFO_EXTENSION))] ?? null;

        return is_string($signal) ? $signal : null;
    }

    private function isExcluded(string $path, string $rootPath): bool
    {
        $relativePath = ltrim(str_replace($rootPath, '', $path), '/');

        foreach (self::EXCLUDED_DIRECTORIES as $directory) {
            if ($relativePath === $directory || str_starts_with($relativePath, $directory . '/')) {
                return true;
            }
        }

        return false;
    }
}
