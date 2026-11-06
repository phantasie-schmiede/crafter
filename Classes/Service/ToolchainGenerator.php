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

use function array_key_last;
use function array_keys;
use function array_merge;
use function implode;
use function is_array;
use function json_encode;
use function ksort;
use function sort;
use function sprintf;
use function str_starts_with;

/**
 * Assembles the quality tooling from `crafter.tools`. Every tool declares the npm scripts it needs, its
 * require-dev packages and the configuration skeletons it owns. The tool selection is derived from the content
 * profile, so the generated composer.json, package.json and AGENTS.md always match what a given extension
 * actually contains.
 *
 * A tool is active when the profile contains one of the signals of its `when` list, e.g. Prettier for HTML, XML
 * or XLF files and PHP-CS-Fixer for PHP files. Tools without a `when` list are always active.
 *
 * The managed composer scripts are the bootstrap commands (the profile-dependent npm install and the `qa link`
 * call) and the test:all aggregate, which collects every test:* script (except the coverage runs) the enabled
 * tools declare. The shared QA scripts and the lint:all, fix:all and qa aggregates are owned by the psbits/qa
 * package: `qa link` adds them to the caller composer.json on every composer install/update, so the generator
 * does not duplicate them. The test scripts themselves (test:unit, test:coverage, ...) stay under repository
 * management.
 */
final class ToolchainGenerator
{
    public function __construct(
        protected readonly ConfigurationService $configurationService,
    ) {
    }

    /**
     * Injectable configuration files of the enabled tools, skeleton identifier => target path.
     *
     * @return array<string, string>
     */
    public function getConfigFiles(ContentProfile $profile, ?string $extensionKey = null): array
    {
        $configFiles = [];

        foreach ($this->getEnabledTools($profile, $extensionKey) as $toolName) {
            $files = is_array($this->getTools($extensionKey)[$toolName]['configFiles'] ?? null)
                ? $this->getTools($extensionKey)[$toolName]['configFiles']
                : [];

            foreach ($files as $identifier => $relativePath) {
                $configFiles[(string)$identifier] = (string)$relativePath;
            }
        }

        return $configFiles;
    }

    /**
     * Names of the tools that apply to the given profile, in configuration order.
     *
     * @return string[]
     */
    public function getEnabledTools(ContentProfile $profile, ?string $extensionKey = null): array
    {
        $enabledTools = [];

        foreach ($this->getTools($extensionKey) as $name => $tool) {
            $when = is_array($tool['when'] ?? null) ? $tool['when'] : [];

            if ([] !== $when && !$this->matchesProfile($when, $profile)) {
                continue;
            }

            $enabledTools[] = (string)$name;
        }

        return $enabledTools;
    }

    /**
     * @return array<string, string>
     */
    public function getManagedComposerRequireDev(ContentProfile $profile, ?string $extensionKey = null): array
    {
        $requireDev = [];

        foreach ($this->getEnabledTools($profile, $extensionKey) as $toolName) {
            $toolRequireDev = is_array($this->getTools($extensionKey)[$toolName]['requireDev'] ?? null)
                ? $this->getTools($extensionKey)[$toolName]['requireDev']
                : [];

            foreach ($toolRequireDev as $package => $version) {
                $requireDev[(string)$package] = (string)$version;
            }
        }

        // Version pins and additional packages a project sets itself always win over the tool defaults.
        $explicitRequireDev = $this->getMap('defaults.composerRequireDev', $extensionKey);

        foreach ($explicitRequireDev as $package => $version) {
            $requireDev[$package] = $version;
        }

        ksort($requireDev);

        return $requireDev;
    }

    /**
     * The composer scripts the generator manages: the bootstrap commands and the test aggregate.
     *
     * @return array<string, mixed>
     */
    public function getManagedComposerScripts(ContentProfile $profile, ?string $extensionKey = null): array
    {
        $scripts = [
            'post-install-cmd' => array_merge(
                $this->needsNode($profile, $extensionKey) ? ['npm install'] : [],
                ['.Build/bin/qa link']
            ),
            'post-update-cmd'  => ['.Build/bin/qa link'],
            'test:all'         => $this->getTestMembers($profile, $extensionKey),
        ];

        ksort($scripts);

        return $scripts;
    }

    /**
     * @return array<string, string>
     */
    public function getManagedPackageDevDependencies(ContentProfile $profile, ?string $extensionKey = null): array
    {
        $dependencies = [];

        foreach ($this->getEnabledTools($profile, $extensionKey) as $toolName) {
            $toolDependencies = is_array($this->getTools($extensionKey)[$toolName]['packageDevDependencies'] ?? null)
                ? $this->getTools($extensionKey)[$toolName]['packageDevDependencies']
                : [];

            foreach ($toolDependencies as $package => $version) {
                $dependencies[(string)$package] = (string)$version;
            }
        }

        ksort($dependencies);

        return $dependencies;
    }

    /**
     * @return array<string, string>
     */
    public function getManagedPackageScripts(ContentProfile $profile, ?string $extensionKey = null): array
    {
        $scripts = [];

        foreach ($this->getEnabledTools($profile, $extensionKey) as $toolName) {
            $toolScripts = is_array($this->getTools($extensionKey)[$toolName]['packageScripts'] ?? null)
                ? $this->getTools($extensionKey)[$toolName]['packageScripts']
                : [];

            foreach ($toolScripts as $name => $command) {
                $scripts[(string)$name] = (string)$command;
            }
        }

        ksort($scripts);

        return $scripts;
    }

    /**
     * Rendered variable blocks for every stub that depends on the tool selection.
     *
     * @return array<string, string>
     */
    public function getVariables(ContentProfile $profile, ?string $extensionKey = null): array
    {
        $enabledTools = $this->getEnabledTools($profile, $extensionKey);

        return [
            'agentsToolChecks'         => $this->renderAgentToolChecks($enabledTools, $extensionKey),
            'composerDevRequire'       => $this->renderJsonMap(
                $this->getManagedComposerRequireDev($profile, $extensionKey)
            ),
            'composerDevScripts'       => $this->renderComposerScripts(
                $this->getManagedComposerScripts($profile, $extensionKey)
            ),
            'packageDevDependencies'   => $this->renderJsonMap(
                $this->getManagedPackageDevDependencies($profile, $extensionKey)
            ),
            'packageScripts'           => $this->renderJsonMap(
                $this->getManagedPackageScripts($profile, $extensionKey)
            ),
        ];
    }

    public function needsNode(ContentProfile $profile, ?string $extensionKey = null): bool
    {
        foreach ($this->getEnabledTools($profile, $extensionKey) as $toolName) {
            if (true === ($this->getTools($extensionKey)[$toolName]['needsNode'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Renders a composer "scripts" map with 8 space indentation, exactly as ComposerDevSection.stub expects it.
     *
     * @param array<string, mixed> $scripts
     */
    public function renderComposerScripts(array $scripts): string
    {
        $lines = [];

        foreach ($scripts as $name => $value) {
            if (is_array($value)) {
                if ([] === $value) {
                    $lines[] = sprintf('        "%s": [],', $name);

                    continue;
                }

                $lines[] = sprintf('        "%s": [', $name);
                $lines[] = '            "' . implode("\",\n            \"", $value) . '"';
                $lines[] = '        ],';

                continue;
            }

            $lines[] = sprintf('        "%s": "%s",', $name, $value);
        }

        $lastLineIndex         = array_key_last($lines);
        $lines[$lastLineIndex] = rtrim($lines[$lastLineIndex], ',');

        return implode("\n", $lines);
    }

    /**
     * Renders a simple key/value JSON map, e.g. for require-dev or package.json scripts.
     *
     * @param array<string, string> $map
     */
    public function renderJsonMap(array $map): string
    {
        $lines = [];

        foreach ($map as $key => $value) {
            $lines[] = sprintf('%s: %s,', json_encode($key), json_encode($value));
        }

        $lastLineIndex = array_key_last($lines);

        if (null !== $lastLineIndex) {
            $lines[$lastLineIndex] = rtrim($lines[$lastLineIndex], ',');
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function getMap(string $path, ?string $extensionKey): array
    {
        $map = $this->configurationService->getValue($path, $extensionKey, []);

        return is_array($map) ? $map : [];
    }

    /**
     * The members of the test:all aggregate: every test:* script (except the coverage runs) the enabled tools
     * declare as composer scripts.
     *
     * @return list<string>
     */
    private function getTestMembers(ContentProfile $profile, ?string $extensionKey): array
    {
        $members = [];

        foreach ($this->getEnabledTools($profile, $extensionKey) as $toolName) {
            $toolScripts = is_array($this->getTools($extensionKey)[$toolName]['composerScripts'] ?? null)
                ? $this->getTools($extensionKey)[$toolName]['composerScripts']
                : [];

            foreach (array_keys($toolScripts) as $name) {
                if (str_starts_with((string)$name, 'test:') && !str_starts_with((string)$name, 'test:coverage')) {
                    $members[] = '@' . $name;
                }
            }
        }

        sort($members);

        return $members;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getTools(?string $extensionKey): array
    {
        $tools = $this->configurationService->getValue('tools', $extensionKey, []);

        return is_array($tools) ? $tools : [];
    }

    /**
     * @param mixed[] $when
     */
    private function matchesProfile(array $when, ContentProfile $profile): bool
    {
        foreach ($when as $signal) {
            if ($profile->has((string)$signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $enabledTools
     */
    private function renderAgentToolChecks(array $enabledTools, ?string $extensionKey): string
    {
        $bullets = [];

        foreach ($enabledTools as $toolName) {
            $agentBullet = $this->getTools($extensionKey)[$toolName]['agentsBullet'] ?? null;

            if (null !== $agentBullet) {
                $bullets[] = '- ' . $agentBullet;
            }
        }

        return implode("\n", $bullets);
    }
}
