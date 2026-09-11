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
use PSBits\Crafter\Utility\NamingUtility;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Package\Exception\UnknownPackageException;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function is_array;
use function is_string;
use function sprintf;
use function strlen;

/**
 * Class PathResolver
 *
 * Resolves skeleton files via the prioritised `skeletonRootPaths` cascade and builds target file paths from the
 * configurable `paths` map. Generators must never concatenate paths themselves.
 *
 * @package PSBits\Crafter\Service
 */
class PathResolver
{
    public const string FILE_PATH_PREFIX             = 'FILE:';
    public const string SKELETON_FILE_EXTENSION      = '.stub';
    public const string TARGET_EXTENSION_PLACEHOLDER = '{targetExtension}';

    public function __construct(
        protected readonly ConfigurationService $configurationService,
        protected readonly PackageManager       $packageManager,
    ) {
    }

    /**
     * Absolute skeleton root paths, ordered by descending priority. Roots that cannot be resolved are omitted.
     *
     * @return array<int, string>
     */
    public function getResolvedRootPaths(?string $extensionKey = null): array
    {
        $resolvedRootPaths = [];

        foreach ($this->getSkeletonRootPaths($extensionKey) as $priority => $rootPath) {
            $absoluteRootPath = $this->resolveRootPath($rootPath, $extensionKey);

            if (null !== $absoluteRootPath) {
                $resolvedRootPaths[$priority] = $absoluteRootPath;
            }
        }

        return $resolvedRootPaths;
    }

    /**
     * Returns all lookup locations for a skeleton, ordered by descending priority.
     *
     * @return array<int, array{priority: int, root: string, path: string, exists: bool}>
     */
    public function getSkeletonCandidates(string $identifier, ?string $extensionKey = null): array
    {
        $candidates   = [];
        $rawRootPaths = $this->getSkeletonRootPaths($extensionKey);

        foreach ($this->getResolvedRootPaths($extensionKey) as $priority => $absoluteRootPath) {
            $absoluteFilePath = $absoluteRootPath . $this->normalizeIdentifier($identifier);
            $candidates[]     = [
                'exists'   => is_file($absoluteFilePath),
                'path'     => $absoluteFilePath,
                'priority' => $priority,
                'root'     => $rawRootPaths[$priority],
            ];
        }

        return $candidates;
    }

    /**
     * Enumerates every skeleton available in any root path and states which root currently wins.
     *
     * @return array<string, array{priority: int, path: string, overridden: bool}>
     */
    public function listSkeletons(?string $extensionKey = null): array
    {
        $skeletons = [];

        foreach ($this->getResolvedRootPaths($extensionKey) as $priority => $absoluteRootPath) {
            if (!is_dir($absoluteRootPath)) {
                continue;
            }

            foreach (GeneralUtility::getAllFilesAndFoldersInPath(
                [],
                $absoluteRootPath,
                ltrim(self::SKELETON_FILE_EXTENSION, '.')
            ) as $absoluteFilePath) {
                $identifier = substr(
                    substr($absoluteFilePath, strlen($absoluteRootPath)),
                    0,
                    -strlen(self::SKELETON_FILE_EXTENSION)
                );

                // Roots are iterated by descending priority, so the first hit is the winning one.
                if (isset($skeletons[$identifier])) {
                    $skeletons[$identifier]['overridden'] = true;

                    continue;
                }

                $skeletons[$identifier] = [
                    'overridden' => false,
                    'path'       => $absoluteFilePath,
                    'priority'   => $priority,
                ];
            }
        }

        ksort($skeletons);

        return $skeletons;
    }

    /**
     * Absolute path of an extension directory. Falls back to the configured `extensionRootPath` for extensions that
     * do not exist yet, which is required by `crafter:make:extension`.
     */
    public function resolveExtensionPath(string $extensionKey): string
    {
        try {
            return rtrim($this->packageManager->getPackage($extensionKey)->getPackagePath(), '/') . '/';
        } catch (UnknownPackageException) {
            $extensionRootPath = $this->resolveRootPath(
                (string)$this->configurationService->getValue(
                    'extensionRootPath',
                    $extensionKey,
                    self::FILE_PATH_PREFIX . 'packages/'
                ),
                $extensionKey
            );

            if (null === $extensionRootPath) {
                $extensionRootPath = rtrim(Environment::getExtensionsPath(), '/') . '/';
            }

            return $extensionRootPath . $extensionKey . '/';
        }
    }

    /**
     * PHP namespace of an extension. Read from the PSR-4 autoload mapping of composer.json to stay correct for
     * extensions that do not follow the vendor/extension name convention.
     */
    public function resolveNamespace(string $extensionKey, string $fallbackVendor = ''): string
    {
        $composerFilePath = $this->resolveExtensionPath($extensionKey) . 'composer.json';

        if (is_file($composerFilePath)) {
            $composerConfiguration = json_decode(
                (string)file_get_contents($composerFilePath),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            foreach ($composerConfiguration['autoload']['psr-4'] ?? [] as $namespace => $directory) {
                if ('Classes' === trim((string)$directory, '/')) {
                    return trim((string)$namespace, '\\');
                }
            }
        }

        return NamingUtility::buildNamespace(
            $fallbackVendor,
            NamingUtility::toUpperCamelCase($extensionKey)
        );
    }

    /**
     * Returns the absolute path of the highest prioritised skeleton file.
     */
    public function resolveSkeleton(string $identifier, ?string $extensionKey = null): string
    {
        foreach ($this->getSkeletonCandidates($identifier, $extensionKey) as $candidate) {
            if (true === $candidate['exists']) {
                return $candidate['path'];
            }
        }

        throw new CrafterException(
            sprintf(
                'Skeleton "%s" not found in any of these root paths: %s',
                $identifier,
                implode(', ', $this->getSkeletonRootPaths($extensionKey))
            ),
            1757426403
        );
    }

    /**
     * Builds the absolute target path for a generated file.
     *
     * @param array<string, string> $placeholders
     */
    public function resolveTargetPath(string $type, string $extensionKey, array $placeholders = []): string
    {
        $pathTemplate = $this->configurationService->getValue('paths.' . $type, $extensionKey);

        if (!is_string($pathTemplate) || '' === $pathTemplate) {
            throw new CrafterException(
                sprintf('No path configured for type "%s". Add it to crafter.paths.%s.', $type, $type),
                1757426404
            );
        }

        return $this->resolveExtensionPath($extensionKey) . ltrim(
            $this->replacePlaceholders($pathTemplate, $placeholders),
            '/'
        );
    }

    /**
     * @return array<int, string>
     */
    private function getSkeletonRootPaths(?string $extensionKey): array
    {
        $rootPaths = $this->configurationService->getValue('skeletonRootPaths', $extensionKey, []);

        if (!is_array($rootPaths) || [] === $rootPaths) {
            throw new CrafterException('No skeletonRootPaths configured.', 1757426405);
        }

        krsort($rootPaths);

        return $rootPaths;
    }

    private function normalizeIdentifier(string $identifier): string
    {
        $identifier = ltrim(str_replace('\\', '/', $identifier), '/');

        if (!str_ends_with($identifier, self::SKELETON_FILE_EXTENSION)) {
            $identifier .= self::SKELETON_FILE_EXTENSION;
        }

        return $identifier;
    }

    /**
     * @param array<string, string> $placeholders
     */
    private function replacePlaceholders(string $subject, array $placeholders): string
    {
        foreach ($placeholders as $key => $value) {
            $subject = str_replace('{' . $key . '}', $value, $subject);
        }

        // Collapse empty optional path segments, e.g. an empty {path} in ViewHelpers/{path}/X.php.
        return preg_replace('#/{2,}#', '/', $subject) ?? $subject;
    }

    /**
     * Returns null if the root path references the target extension but no extension key is given.
     */
    private function resolveRootPath(string $rootPath, ?string $extensionKey): ?string
    {
        if (str_contains($rootPath, self::TARGET_EXTENSION_PLACEHOLDER)) {
            if (null === $extensionKey) {
                return null;
            }

            $rootPath = str_replace(self::TARGET_EXTENSION_PLACEHOLDER, $extensionKey, $rootPath);
        }

        if (str_starts_with($rootPath, self::FILE_PATH_PREFIX)) {
            $rootPath = rtrim(Environment::getProjectPath(), '/') . '/' . ltrim(
                substr($rootPath, strlen(self::FILE_PATH_PREFIX)),
                '/'
            );
        } else {
            $rootPath = GeneralUtility::getFileAbsFileName($rootPath);
        }

        if ('' === $rootPath) {
            return null;
        }

        return rtrim($rootPath, '/') . '/';
    }
}
