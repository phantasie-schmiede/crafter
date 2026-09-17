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
use function array_merge;
use function in_array;
use function is_array;
use function json_encode;
use function ksort;
use function sort;
use function sprintf;
use function str_starts_with;

/**
 * Class ToolchainGenerator
 *
 * Assembles the quality tooling from `crafter.tools`. Every tool declares the composer and npm scripts it needs,
 * its require-dev packages, the configuration skeletons it owns and the workflow steps it contributes. The tool
 * selection is derived from the content profile, so the generated composer.json, package.json, workflow and
 * AGENTS.md always match what a given extension actually contains.
 *
 * A tool is active when the profile contains one of the signals of its `when` list, e.g. Prettier for HTML, XML
 * or XLF files and PHP-CS-Fixer for PHP files. Tools without a `when` list are always active.
 *
 * Build commands are derived by script name: every enabled `fix:*` script is part of `fix:all`, every `lint:*`
 * script (plus `analyze:php`, `audit:composer` and `validate:composer`) of `lint:all`.
 *
 * @package PSBits\Crafter\Service
 */
final class ToolchainGenerator
{
    public function __construct(
        protected readonly ConfigurationService $configurationService,
    ) {
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
     * @return array<string, mixed>
     */
    public function getManagedComposerScripts(ContentProfile $profile, ?string $extensionKey = null): array
    {
        $enabledTools = $this->getEnabledTools($profile, $extensionKey);
        $scripts      = [];
        $needsNode    = false;

        foreach ($enabledTools as $toolName) {
            $tool        = $this->getTools($extensionKey)[$toolName];
            $toolScripts = is_array($tool['composerScripts'] ?? null) ? $tool['composerScripts'] : [];

            foreach ($toolScripts as $name => $command) {
                $scripts[(string)$name] = (string)$command;
            }

            $needsNode = $needsNode || true === ($tool['needsNode'] ?? false);
        }

        $this->addAggregateScripts($scripts, $enabledTools);

        $scripts['post-install-cmd'] = array_merge(
            $needsNode ? ['npm install'] : [],
            ['cp Build/GitHooks/pre-commit .git/hooks/pre-commit']
        );
        $scripts['validate:composer'] = 'composer validate --no-check-publish';

        ksort($scripts);

        return $scripts;
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
     * Rendered variable blocks for every stub that depends on the tool selection.
     *
     * @return array<string, string>
     */
    public function getVariables(ContentProfile $profile, ?string $extensionKey = null): array
    {
        $enabledTools = $this->getEnabledTools($profile, $extensionKey);
        $needsNode    = $this->needsNode($profile, $extensionKey);

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
            'workflowNode'             => true === $needsNode ? $this->renderWorkflowNode() : '',
            'workflowSteps'            => $this->renderWorkflowSteps($enabledTools, $extensionKey),
        ];
    }

    /**
     * @param array<string, mixed> $scripts
     * @param string[]             $enabledTools
     */
    private function addAggregateScripts(array &$scripts, array $enabledTools): void
    {
        $fixMembers  = [];
        $lintMembers = [];

        foreach (array_keys($scripts) as $name) {
            $name = (string)$name;

            if (str_starts_with($name, 'fix:')) {
                $fixMembers[] = $name;
            }

            if (str_starts_with($name, 'lint:')) {
                $lintMembers[] = $name;
            }
        }

        if (in_array('composerAudit', $enabledTools, true)) {
            $lintMembers[] = 'audit:composer';
        }

        if (in_array('phpStan', $enabledTools, true)) {
            $lintMembers[] = 'analyze:php';
        }

        $lintMembers[] = 'validate:composer';

        sort($fixMembers);
        sort($lintMembers);

        if ([] !== $fixMembers) {
            $scripts['fix:all'] = array_map(static fn(string $name): string => '@' . $name, $fixMembers);
        }

        $scripts['lint:all'] = array_map(static fn(string $name): string => '@' . $name, $lintMembers);
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

    private function renderWorkflowNode(): string
    {
        return implode("\n", [
            '      - name: Setup Node.js',
            '        uses: actions/setup-node@v4',
            '        with:',
            "          node-version: '24'",
            '',
            '      - name: Install npm dependencies',
            '        run: npm install',
        ]);
    }

    /**
     * @param string[] $enabledTools
     */
    private function renderWorkflowSteps(array $enabledTools, ?string $extensionKey): string
    {
        $steps = [];

        foreach ($enabledTools as $toolName) {
            $toolSteps = $this->getTools($extensionKey)[$toolName]['workflowSteps'] ?? [];

            if (!is_array($toolSteps)) {
                continue;
            }

            foreach ($toolSteps as $step) {
                if (!is_array($step) || !is_string($step['name'] ?? null) || !is_string($step['run'] ?? null)) {
                    continue;
                }

                $steps[] = sprintf("      - name: %s\n        run: %s", $step['name'], $step['run']);
            }
        }

        return implode("\n\n", $steps);
    }
}
