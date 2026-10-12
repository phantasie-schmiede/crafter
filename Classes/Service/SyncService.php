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
use PSBits\Crafter\Data\WriteResult;
use PSBits\Crafter\Exception\CrafterException;

use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function ltrim;
use function sort;
use function sprintf;
use function str_ends_with;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * Class SyncService
 *
 * Keeps the toolchain of an existing extension aligned with `crafter.tools`: renewable files are rendered from the
 * very same skeletons the generators use, while composer.json and package.json are merged in a structured way so
 * manual entries survive. The renewal policies are defined in `crafter.sync.renewable`:
 *
 * - createIfMissing - written when absent, existing user edits are preserved
 * - merge           - the managed keys are merged into the existing file
 * - overwrite       - always brought back to the current skeleton output
 *
 * The service is used by `crafter:sync`, by the toolchain audit category and automatically after every
 * `crafter:make:*` command through the AfterGenerationEvent.
 *
 * @package PSBits\Crafter\Service
 */
final class SyncService
{
    public const string POLICY_CREATE_IF_MISSING       = 'createIfMissing';
    public const string POLICY_MERGE                   = 'merge';
    public const string POLICY_OVERWRITE               = 'overwrite';
    public const array ALWAYS_MANAGED_COMPOSER_SCRIPTS = ['fix:all', 'lint:all', 'post-install-cmd', 'validate:composer'];

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
     * @return WriteResult[]
     */
    public function sync(string $extensionKey, bool $dryRun = false): array
    {
        $this->fileWriter->reset($dryRun, true);
        $extensionPath = $this->pathResolver->resolveExtensionPath($extensionKey);

        if (!is_dir($extensionPath)) {
            throw new CrafterException(
                sprintf('Extension "%s" has no directory to sync.', $extensionKey),
                1757426450
            );
        }

        $profile = $this->extensionProfileService->fromDirectory($extensionPath);

        foreach ($this->getRenewableEntries($extensionKey) as $identifier => $entry) {
            if (!$this->isApplicable($identifier, $profile, $extensionKey)) {
                continue;
            }

            $target = $extensionPath . ltrim($entry['target'], '/');

            if ($this->isUpToDate($identifier, $extensionKey, $profile, $entry['policy'])) {
                continue;
            }

            $expected = $this->getExpectedContent($identifier, $extensionKey, $profile);

            if (null !== $expected) {
                $this->fileWriter->write($target, $expected);
            }
        }

        return $this->fileWriter->getResults();
    }

    /**
     * Declared renewable files with their policy and target path, in configuration order.
     *
     * @return array<string, array{policy: string, target: string}>
     */
    public function getRenewableEntries(?string $extensionKey = null): array
    {
        $catalogue = $this->flattenExtensionFiles($extensionKey);
        $entries   = [];

        foreach ($this->getRenewableConfig($extensionKey) as $entry) {
            if (is_string($entry)) {
                $identifier = $entry;
                $policy     = self::POLICY_OVERWRITE;
            } else {
                $identifier = (string)($entry['identifier'] ?? '');
                $policy     = (string)($entry['policy'] ?? self::POLICY_OVERWRITE);
            }

            if ('' === $identifier || !isset($catalogue[$identifier])) {
                continue;
            }

            $entries[$identifier] = [
                'policy' => $this->normalizePolicy($policy),
                'target' => $catalogue[$identifier],
            ];
        }

        return $entries;
    }

    /**
     * Rendered (or merged) content a renewable file would have after a sync. Returns null when the file is not
     * applicable for the current profile or cannot be managed, e.g. because an existing JSON file cannot be parsed.
     */
    public function getExpectedContent(
        string          $identifier,
        ?string         $extensionKey = null,
        ?ContentProfile $profile = null,
    ): ?string {
        $entry = $this->getRenewableEntries($extensionKey)[$identifier] ?? null;

        if (null === $entry) {
            return null;
        }

        $extensionPath = $this->pathResolver->resolveExtensionPath((string)$extensionKey);
        $profile ??= $this->extensionProfileService->fromDirectory($extensionPath);
        $target = $extensionPath . ltrim($entry['target'], '/');

        /*
         * Without an existing file the whole skeleton is rendered, so a fresh composer.json or package.json is
         * complete instead of only containing the managed keys.
         */
        if (self::POLICY_MERGE === $entry['policy'] && is_file($target)) {
            return $this->mergeJsonFile($target, $extensionKey, $profile);
        }

        return $this->skeletonRenderer->render(
            $identifier,
            $this->buildVariables((string)$extensionKey, $extensionPath, $profile),
            $extensionKey
        );
    }

    /**
     * States whether a renewable file already matches what the generators would write. Merge files are compared
     * semantically, i.e. only the managed keys are checked, so formatting differences do not cause drift.
     */
    public function isUpToDate(
        string          $identifier,
        ?string         $extensionKey = null,
        ?ContentProfile $profile = null,
        ?string         $policy = null,
    ): bool {
        $entry = $this->getRenewableEntries($extensionKey)[$identifier] ?? null;

        if (null === $entry) {
            return true;
        }

        $extensionPath = $this->pathResolver->resolveExtensionPath((string)$extensionKey);
        $profile ??= $this->extensionProfileService->fromDirectory($extensionPath);
        $policy  ??= $entry['policy'];
        $target = $extensionPath . ltrim($entry['target'], '/');

        if (!is_file($target)) {
            return false;
        }

        if (self::POLICY_CREATE_IF_MISSING === $policy) {
            return true;
        }

        if (self::POLICY_MERGE === $policy) {
            return $this->isMergeUpToDate($target, $extensionKey, $profile);
        }

        $expected = $this->skeletonRenderer->render(
            $identifier,
            $this->buildVariables($extensionKey, $extensionPath, $profile),
            $extensionKey
        );

        return $expected === (string)file_get_contents($target);
    }

    /**
     * Merges the managed keys into an existing JSON file and returns it re-encoded in canonical JSON. Returns null
     * when parsing fails.
     */
    private function mergeJsonFile(
        string         $absoluteFilePath,
        ?string        $extensionKey,
        ContentProfile $profile,
    ): ?string {
        $existing = $this->readJsonFile($absoluteFilePath);

        if (null === $existing) {
            return null;
        }

        if (str_ends_with($absoluteFilePath, 'package.json')) {
            $merged            = $existing;
            $merged['scripts'] = $this->mergeMap(
                $existing['scripts'] ?? [],
                $this->toolchainGenerator->getManagedPackageScripts($profile, $extensionKey),
                $this->getManagedKeys('packageScripts', $extensionKey)
            );
            $merged['devDependencies'] = $this->mergeMap(
                $existing['devDependencies'] ?? [],
                $this->toolchainGenerator->getManagedPackageDevDependencies($profile, $extensionKey),
                $this->getManagedKeys('packageDevDependencies', $extensionKey)
            );

            return $this->encodeJson($merged, true);
        }

        $merged            = $existing;
        $merged['scripts'] = $this->mergeMap(
            $existing['scripts'] ?? [],
            $this->toolchainGenerator->getManagedComposerScripts($profile, $extensionKey),
            array_merge(
                $this->getManagedKeys('composerScripts', $extensionKey),
                self::ALWAYS_MANAGED_COMPOSER_SCRIPTS
            )
        );
        $merged['require-dev'] = $this->mergeMap(
            $existing['require-dev'] ?? [],
            $this->toolchainGenerator->getManagedComposerRequireDev($profile, $extensionKey),
            $this->getManagedKeys('requireDev', $extensionKey)
        );

        return $this->encodeJson($merged, false);
    }

    /**
     * Compares only the managed keys of an existing JSON file with what the generators would produce, so
     * formatting, key order and user owned keys never cause a finding.
     */
    private function isMergeUpToDate(
        string         $absoluteFilePath,
        ?string        $extensionKey,
        ContentProfile $profile,
    ): bool {
        $existing = $this->readJsonFile($absoluteFilePath);

        if (null === $existing) {
            return true;
        }

        if (str_ends_with($absoluteFilePath, 'package.json')) {
            return $this->mapsEqual(
                $existing['scripts'] ?? [],
                $this->mergeMap(
                    $existing['scripts'] ?? [],
                    $this->toolchainGenerator->getManagedPackageScripts($profile, $extensionKey),
                    $this->getManagedKeys('packageScripts', $extensionKey)
                )
            ) && $this->mapsEqual(
                $existing['devDependencies'] ?? [],
                $this->mergeMap(
                    $existing['devDependencies'] ?? [],
                    $this->toolchainGenerator->getManagedPackageDevDependencies($profile, $extensionKey),
                    $this->getManagedKeys('packageDevDependencies', $extensionKey)
                )
            );
        }

        return $this->mapsEqual(
            $existing['scripts'] ?? [],
            $this->mergeMap(
                $existing['scripts'] ?? [],
                $this->toolchainGenerator->getManagedComposerScripts($profile, $extensionKey),
                array_merge(
                    $this->getManagedKeys('composerScripts', $extensionKey),
                    self::ALWAYS_MANAGED_COMPOSER_SCRIPTS
                )
            )
        ) && $this->mapsEqual(
            $existing['require-dev'] ?? [],
            $this->mergeMap(
                $existing['require-dev'] ?? [],
                $this->toolchainGenerator->getManagedComposerRequireDev($profile, $extensionKey),
                $this->getManagedKeys('requireDev', $extensionKey)
            )
        );
    }

    /**
     * @param array<string, string> $existing
     * @param array<string, mixed>  $generated
     * @param string[]              $managedNames
     *
     * @return array<string, mixed>
     */
    private function mergeMap(array $existing, array $generated, array $managedNames): array
    {
        $map = [];

        foreach ($existing as $name => $value) {
            if (!in_array((string)$name, $managedNames, true)) {
                $map[(string)$name] = $value;
            }
        }

        foreach ($generated as $name => $value) {
            $map[(string)$name] = $value;
        }

        ksort($map);

        return $map;
    }

    /**
     * Compares two script or dependency maps ignoring key order, used by the semantic up-to-date check.
     *
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    private function mapsEqual(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }

        ksort($a);
        ksort($b);

        return $a === $b;
    }

    /**
     * Names of map keys the sync manages within a given tool section, i.e. entries that may be replaced or dropped.
     *
     * @return string[]
     */
    private function getManagedKeys(string $toolKey, ?string $extensionKey): array
    {
        $names = [];

        foreach ($this->getTools($extensionKey) as $tool) {
            $values = $tool[$toolKey] ?? [];

            if (!is_array($values)) {
                continue;
            }

            foreach (array_keys($values) as $name) {
                $names[] = (string)$name;
            }
        }

        sort($names);

        return array_values(array_unique($names));
    }

    /**
     * @return array<string, string>
     */
    private function flattenExtensionFiles(?string $extensionKey): array
    {
        $files = [];

        foreach ($this->configurationService->getValue('extensionFiles', $extensionKey, []) as $group) {
            if (!is_array($group)) {
                continue;
            }

            foreach ($group as $identifier => $relativePath) {
                $files[(string)$identifier] = (string)$relativePath;
            }
        }

        return $files;
    }

    /**
     * @return mixed[]
     */
    private function getRenewableConfig(?string $extensionKey): array
    {
        $renewable = $this->configurationService->getValue('sync.renewable', $extensionKey, []);

        return is_array($renewable) ? $renewable : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getTools(?string $extensionKey): array
    {
        $tools = $this->configurationService->getValue('tools', $extensionKey, []);

        return is_array($tools) ? $tools : [];
    }

    private function normalizePolicy(string $policy): string
    {
        $validPolicies = [
            self::POLICY_CREATE_IF_MISSING,
            self::POLICY_MERGE,
            self::POLICY_OVERWRITE,
        ];

        return in_array($policy, $validPolicies, true) ? $policy : self::POLICY_OVERWRITE;
    }

    /**
     * States whether a renewable file belongs to the toolchain of the given profile at all.
     */
    public function isApplicable(string $identifier, ContentProfile $profile, ?string $extensionKey): bool
    {
        $enabledTools = $this->toolchainGenerator->getEnabledTools($profile, $extensionKey);

        foreach ($this->getTools($extensionKey) as $toolName => $tool) {
            if (isset($tool['configFiles'][$identifier])) {
                return in_array((string)$toolName, $enabledTools, true);
            }
        }

        if (str_starts_with($identifier, 'Extension/Quality/')
            || str_starts_with($identifier, 'Extension/Workflow/')
            || 'Extension/PackageJson' === $identifier) {
            return [] !== $enabledTools;
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    private function buildVariables(string $extensionKey, string $extensionPath, ContentProfile $profile): array
    {
        $composer  = $this->readJsonFile($extensionPath . 'composer.json') ?? [];
        $namespace = $this->pathResolver->resolveNamespace($extensionKey);

        return array_merge(
            [
                'composerName'     => (string)($composer['name'] ?? ''),
                'description'      => (string)($composer['description'] ?? ''),
                'extensionKey'     => $extensionKey,
                'gitHubRepository' => $this->resolveGitHubRepository($composer, $extensionKey),
                'license'          => $this->resolveLicense($composer),
                'namespace'        => $namespace,
                'packageTitle'     => $this->resolvePackageTitle($namespace),
                'repositoryName'   => str_replace('_', '-', $extensionKey),
                'vendor'           => $this->resolveVendor($namespace, $composer),
            ],
            $this->toolchainGenerator->getVariables($profile, $extensionKey)
        );
    }

    /**
     * @param array<string, mixed> $composer
     */
    private function resolveGitHubRepository(array $composer, string $extensionKey): string
    {
        $name = trim((string)($composer['name'] ?? ''));

        if ('' !== $name && str_contains($name, '/')) {
            return $name;
        }

        return $this->resolveVendor('', $composer) . '/' . str_replace('_', '-', $extensionKey);
    }

    /**
     * @param array<string, mixed> $composer
     */
    private function resolveLicense(array $composer): string
    {
        $license = $composer['license'] ?? '';

        if (is_array($license)) {
            return implode(', ', array_values(array_unique(array_map('strval', $license))));
        }

        return trim((string)$license);
    }

    /**
     * @param array<string, mixed> $composer
     */
    private function resolveVendor(string $namespace, array $composer): string
    {
        $namespaceSegments = explode('\\', $namespace);

        if ('' !== $namespaceSegments[0]) {
            return $namespaceSegments[0];
        }

        $name = trim((string)($composer['name'] ?? ''));

        return str_contains($name, '/') ? explode('/', $name)[0] : '';
    }

    private function resolvePackageTitle(string $namespace): string
    {
        $segments  = explode('\\', $namespace);
        $extension = implode(' ', preg_split('/(?<!^)[A-Z]/', end($segments) ?: ''));

        return trim($extension);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJsonFile(string $absoluteFilePath): ?array
    {
        if (!is_file($absoluteFilePath)) {
            return [];
        }

        $data = json_decode((string)file_get_contents($absoluteFilePath), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encodeJson(array $data, bool $twoSpaces): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === $json) {
            return '';
        }

        return ($twoSpaces ? $this->reindent($json) : $json) . "\n";
    }

    /**
     * Halves the four space indentation json_encode uses, to match the two space style of package.json.
     */
    private function reindent(string $json): string
    {
        $lines = explode("\n", $json);

        foreach ($lines as &$line) {
            $indentation = strlen($line) - strlen(ltrim($line));

            if (0 < $indentation) {
                $line = str_repeat(' ', (int)($indentation / 2)) . substr($line, $indentation);
            }
        }

        unset($line);

        return implode("\n", $lines);
    }
}
