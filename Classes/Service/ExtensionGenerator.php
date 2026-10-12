<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Service;

use PSBits\Crafter\Data\ExtensionBlueprint;
use PSBits\Crafter\Data\WriteResult;
use PSBits\Crafter\Exception\CrafterException;

use function in_array;
use function is_array;
use function sprintf;
use function str_starts_with;
use function strlen;

/**
 * Class ExtensionGenerator
 *
 * Creates the base structure of a new extension. Which files belong to which feature group is defined in
 * `crafter.extensionFiles` and therefore fully configurable without touching this class.
 *
 * @package PSBits\Crafter\Service
 */
readonly class ExtensionGenerator
{
    public const string CORE_GROUP                     = 'core';
    public const string SKELETON_COMPOSER_DEV_SECTION  = 'Extension/ComposerDevSection';
    public const string SKELETON_EXTENSION_INFORMATION = 'Extension/ExtensionInformation';
    public const string SKELETON_PACKAGE_JSON          = 'Extension/PackageJson';
    public const string QUALITY_GROUP                  = 'quality';

    public function __construct(
        protected ConfigurationService    $configurationService,
        protected ExtensionProfileService $extensionProfileService,
        protected FileWriter              $fileWriter,
        protected PathResolver            $pathResolver,
        protected SkeletonRenderer        $skeletonRenderer,
        protected ToolchainGenerator      $toolchainGenerator,
    ) {
    }

    /**
     * @param string[] $groups
     *
     * @return WriteResult[]
     */
    public function generate(ExtensionBlueprint $blueprint, array $groups): array
    {
        $extensionPath = $this->pathResolver->resolveExtensionPath($blueprint->extensionKey);
        $files         = $this->getFiles($blueprint->extensionKey, $groups);
        $profile       = $this->extensionProfileService->fromPlannedFiles(array_values($files));
        $variables     = $this->buildVariables(
            $blueprint,
            $groups,
            $this->toolchainGenerator->getVariables($profile, $blueprint->extensionKey)
        );

        foreach ($files as $identifier => $relativePath) {
            $this->fileWriter->write(
                $extensionPath . ltrim($relativePath, '/'),
                $this->skeletonRenderer->render($identifier, $variables, $blueprint->extensionKey)
            );
        }

        return $this->fileWriter->getResults();
    }

    /**
     * All groups defined in `crafter.extensionFiles`, in configuration order.
     *
     * @return string[]
     */
    public function getAvailableGroups(?string $extensionKey = null): array
    {
        return array_keys($this->getExtensionFiles($extensionKey));
    }

    /**
     * Groups that are preselected when the command runs without explicit selection.
     *
     * @return string[]
     */
    public function getDefaultGroups(?string $extensionKey = null): array
    {
        $defaultGroups = $this->configurationService->getValue(
            'defaults.extensionFileGroups',
            $extensionKey,
            []
        );

        if (!is_array($defaultGroups)) {
            return [self::CORE_GROUP];
        }

        return array_values(array_intersect(
            $this->getAvailableGroups($extensionKey),
            array_map(static fn(mixed $group): string => (string)$group, $defaultGroups)
        ));
    }

    /**
     * Skeleton identifier => target path relative to the extension directory.
     *
     * @param string[] $groups
     *
     * @return array<string, string>
     */
    public function getFiles(?string $extensionKey, array $groups): array
    {
        $extensionFiles = $this->getExtensionFiles($extensionKey);
        $files          = [];
        $unknownGroups  = array_diff($groups, array_keys($extensionFiles));

        if ([] !== $unknownGroups) {
            throw new CrafterException(
                sprintf(
                    'Unknown extension file group(s): %s. Available groups: %s',
                    implode(', ', $unknownGroups),
                    implode(', ', array_keys($extensionFiles))
                ),
                1757426409
            );
        }

        foreach ($extensionFiles as $group => $groupFiles) {
            if (self::CORE_GROUP !== $group && !in_array($group, $groups, true)) {
                continue;
            }

            foreach ($groupFiles as $identifier => $relativePath) {
                $files[(string)$identifier] = (string)$relativePath;
            }
        }

        ksort($files);

        return $this->pruneFiles($files, $extensionKey);
    }

    /**
     * Configuration files of a tool are only created when the extension profile enables that tool. The remaining
     * quality group files need at least one enabled tool, otherwise no tooling would be configured at all.
     *
     * @param array<string, string> $files
     *
     * @return array<string, string>
     */
    private function pruneFiles(array $files, ?string $extensionKey): array
    {
        $profile            = $this->extensionProfileService->fromPlannedFiles(array_values($files));
        $enabledConfigFiles = $this->toolchainGenerator->getConfigFiles($profile, $extensionKey);
        $enabledTools       = $this->toolchainGenerator->getEnabledTools($profile, $extensionKey);
        $toolConfigFiles    = [];
        $toolsConfiguration = $this->configurationService->getValue('tools', $extensionKey, []);

        if (is_array($toolsConfiguration)) {
            foreach ($toolsConfiguration as $tool) {
                $configFiles = is_array($tool) && is_array($tool['configFiles'] ?? null) ? $tool['configFiles'] : [];

                foreach (array_keys($configFiles) as $identifier) {
                    $toolConfigFiles[(string)$identifier] = true;
                }
            }
        }

        foreach ($files as $identifier => $relativePath) {
            if (isset($enabledConfigFiles[$identifier])) {
                continue;
            }

            if (isset($toolConfigFiles[$identifier]) || (self::isToolchainFile($identifier) && [] === $enabledTools)) {
                unset($files[$identifier]);
            }
        }

        return $files;
    }

    private static function isToolchainFile(string $identifier): bool
    {
        return str_starts_with($identifier, 'Extension/Quality/')
            || str_starts_with($identifier, 'Extension/Workflow/')
            || self::SKELETON_PACKAGE_JSON === $identifier;
    }

    /**
     * @param string[]              $groups
     * @param array<string, string> $toolchainVariables
     *
     * @return array<string, string>
     */
    private function buildVariables(ExtensionBlueprint $blueprint, array $groups, array $toolchainVariables): array
    {
        $extensionKey = $blueprint->extensionKey;
        $variables    = [
            'author'             => $blueprint->author,
            'authorEmail'        => $blueprint->authorEmail,
            'composerName'       => $blueprint->composerName,
            'description'        => $blueprint->description,
            'emConfCategory'     => $blueprint->category,
            'emConfState'        => $blueprint->state,
            'extensionKey'       => $extensionKey,
            'extensionName'      => $blueprint->extensionName,
            'extensionVersion'   => $blueprint->version,
            'gitHubOrganization' => $blueprint->gitHubOrganization,
            'gitHubRepository'   => $blueprint->getGitHubRepository(),
            'license'            => $blueprint->license,
            'namespace'          => $blueprint->namespace,
            'namespaceEscaped'   => str_replace('\\', '\\\\', $blueprint->namespace),
            'packageTitle'       => $blueprint->packageTitle,
            'repositoryName'     => $blueprint->getRepositoryName(),
            'signaturePrefix'    => $blueprint->getSignaturePrefix(),
            'title'              => $blueprint->title,
            'vendor'             => $blueprint->vendor,
        ];

        $variables['composerKeywords'] = $this->renderJsonList([
            $blueprint->category,
            'typo3',
            $blueprint->getRepositoryName(),
        ]);
        $variables['composerRequire'] = $this->renderJsonMap(
            $this->getMap('defaults.composerRequire', $extensionKey)
        );
        $variables['emConfDepends'] = $this->renderPhpMap(
            $this->getMap('defaults.emConfDepends', $extensionKey)
        );

        // Optional sections are skeletons of their own so they stay overridable.
        $variables['fileHeader'] = rtrim($this->skeletonRenderer->render(
            SkeletonRenderer::FILE_HEADER_SKELETON,
            $variables,
            $extensionKey
        ));

        $variables                       = array_merge($variables, $toolchainVariables);
        $hasToolchain                    = in_array(self::QUALITY_GROUP, $groups, true);
        $variables['agentsToolChecks']   = $hasToolchain ? $variables['agentsToolChecks'] : '';
        $variables['composerDevSection'] = $hasToolchain
            ? rtrim($this->skeletonRenderer->render(self::SKELETON_COMPOSER_DEV_SECTION, $variables, $extensionKey))
            : '';

        return $variables;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function getExtensionFiles(?string $extensionKey): array
    {
        $extensionFiles = $this->configurationService->getValue('extensionFiles', $extensionKey, []);

        if (!is_array($extensionFiles) || [] === $extensionFiles) {
            throw new CrafterException('No extensionFiles configured.', 1757426410);
        }

        return $extensionFiles;
    }

    /**
     * @return array<string, string>
     */
    private function getMap(string $path, ?string $extensionKey): array
    {
        $map = $this->configurationService->getValue($path, $extensionKey, []);

        return is_array($map) ? $map : [];
    }

    /**
     * @param string[] $values
     */
    private function renderJsonList(array $values): string
    {
        $values = array_values(array_unique(array_filter($values)));
        sort($values);

        return implode(",\n", array_map(
            static fn(string $value): string => sprintf('"%s"', $value),
            $values
        ));
    }

    /**
     * @param array<string, string> $map
     */
    private function renderJsonMap(array $map): string
    {
        ksort($map);
        $lines = [];

        foreach ($map as $key => $value) {
            $lines[] = sprintf('"%s": "%s"', $key, $value);
        }

        return implode(",\n", $lines);
    }

    /**
     * Renders an aligned PHP array body, matching the `align_by_scope` style of the house code sniffer.
     *
     * @param array<string, string> $map
     */
    private function renderPhpMap(array $map): string
    {
        ksort($map);
        $keyLength = 0;

        foreach (array_keys($map) as $key) {
            $keyLength = max($keyLength, strlen((string)$key));
        }

        $lines = [];

        foreach ($map as $key => $value) {
            $lines[] = sprintf("'%s'%s => '%s',", $key, str_repeat(' ', $keyLength - strlen((string)$key)), $value);
        }

        return implode("\n", $lines);
    }
}
