<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PSBits\Crafter\Tests\Unit\AbstractCrafterTestCase;

use function array_keys;
use function file_put_contents;
use function mkdir;

/**
 * Verifies the tool selection and the assembled composer/package maps against real profiles. The expected values
 * mirror the shipped default tool configuration, so every assertion also pins the configuration itself.
 */
final class ToolchainGeneratorTest extends AbstractCrafterTestCase
{
    #[Test]
    public function agentBulletsReflectTheEnabledTools(): void
    {
        $profile   = $this->extensionProfileService->fromPlannedFiles(['Resources/Private/Templates/Product/List.html']);
        $variables = $this->toolchainGenerator->getVariables($profile);

        self::assertStringContainsString('- `lint:classes`', $variables['agentsToolChecks']);
        self::assertStringContainsString('- `lint:htmlxml`', $variables['agentsToolChecks']);
        self::assertStringContainsString('- `audit:composer`', $variables['agentsToolChecks']);
        self::assertStringNotContainsString('lint:php', $variables['agentsToolChecks']);
    }

    #[Test]
    public function directoryProfileIgnoresBuildAndVendor(): void
    {
        $extensionPath = $this->getExtensionFilePath('');
        mkdir($extensionPath . '/Build/Quality', 0777, true);
        mkdir($extensionPath . '/Classes', 0777, true);
        mkdir($extensionPath . '/vendor', 0777, true);
        file_put_contents($extensionPath . '/Build/Quality/phpstan.neon.dist', '');
        file_put_contents($extensionPath . '/Classes/Stuff.php', '');
        file_put_contents($extensionPath . '/vendor/autoload.php', '');

        $profile = $this->extensionProfileService->fromDirectory($extensionPath);

        self::assertSame(['php'], array_keys($profile->signals));
    }

    #[Test]
    public function emptyProfileEnablesOnlyTheAlwaysOnTools(): void
    {
        $profile = $this->extensionProfileService->fromPlannedFiles([]);

        self::assertSame(['composerAudit'], $this->toolchainGenerator->getEnabledTools($profile));
    }

    #[Test]
    public function htmlProfileTurnsTheClassLinterAndPrettierOn(): void
    {
        $profile = $this->extensionProfileService->fromPlannedFiles(['Resources/Private/Templates/Product/List.html']);

        self::assertSame(['classLint', 'composerAudit', 'prettier'], $this->toolchainGenerator->getEnabledTools($profile));
        self::assertTrue($this->toolchainGenerator->needsNode($profile));
        self::assertSame(
            [
                'fix:classes'  => 'node .Build/vendor/psbits/qa/Resources/Quality/lint-class-attributes.mjs',
                'fix:htmlxml'  => 'prettier --config .Build/vendor/psbits/qa/Resources/Quality/prettier.config.mjs --ignore-path Build/Quality/.prettierignore --write "**/*.{html,xml,xlf}"',
                'lint:classes' => 'node .Build/vendor/psbits/qa/Resources/Quality/lint-class-attributes.mjs --dry-run',
                'lint:htmlxml' => 'prettier --config .Build/vendor/psbits/qa/Resources/Quality/prettier.config.mjs --ignore-path Build/Quality/.prettierignore --check "**/*.{html,xml,xlf}"',
            ],
            $this->toolchainGenerator->getManagedPackageScripts($profile)
        );
        self::assertSame(
            [
                '@prettier/plugin-xml'                => '^3.4.2',
                'prettier'                            => '^3.6.2',
                'prettier-plugin-organize-attributes' => '^1.0.0',
            ],
            $this->toolchainGenerator->getManagedPackageDevDependencies($profile)
        );
        self::assertSame(
            ['Extension/Quality/PrettierIgnore' => 'Build/Quality/.prettierignore'],
            $this->toolchainGenerator->getConfigFiles($profile)
        );
    }

    #[Test]
    public function phpProfileContributesThePhpPackagesToRequireDev(): void
    {
        $profile = $this->extensionProfileService->fromPlannedFiles(['Classes/Domain/Model/Product.php']);

        self::assertSame(
            [
                'friendsofphp/php-cs-fixer'     => '^3.95',
                'phpstan/phpstan'               => '^2.2',
                'phpunit/phpunit'               => '^11',
                'psbits/qa'                     => '@dev',
                'roave/security-advisories'     => 'dev-latest',
                'saschaegerer/phpstan-typo3'    => '^2.1 || ^3.0',
                'typo3/testing-framework'       => '^9.5',
            ],
            $this->toolchainGenerator->getManagedComposerRequireDev($profile)
        );
    }

    #[Test]
    public function phpProfileEnablesThePhpToolsAndManagesOnlyTheBootstrapScripts(): void
    {
        $profile = $this->extensionProfileService->fromPlannedFiles(['Classes/Domain/Model/Product.php']);

        self::assertSame(
            ['composerAudit', 'phpCsFixer', 'phpUnit', 'phpStan', 'throwsFixer'],
            $this->toolchainGenerator->getEnabledTools($profile)
        );
        self::assertSame(
            [
                'post-install-cmd' => ['.Build/bin/qa link'],
                'post-update-cmd'  => ['.Build/bin/qa link'],
                'test:all'         => [],
            ],
            $this->toolchainGenerator->getManagedComposerScripts($profile)
        );
        self::assertSame(
            [
                'Extension/Quality/PhpCsFixer',
                'Extension/Quality/PhpStan',
            ],
            array_keys($this->toolchainGenerator->getConfigFiles($profile))
        );
    }

    #[Test]
    public function renderHelpersFormatTheMapsAsTheStubsExpect(): void
    {
        self::assertSame(
            "\"a\": \"1\",\n\"b\": \"2\"",
            $this->toolchainGenerator->renderJsonMap(['a' => '1', 'b' => '2'])
        );
        self::assertSame(
            "        \"a\": [\n            \"x\",\n            \"y\"\n        ],\n        \"b\": \"z\"",
            $this->toolchainGenerator->renderComposerScripts(['a' => ['x', 'y'], 'b' => 'z'])
        );
    }

    #[Test]
    public function typoScriptProfileDoesNotRequireNode(): void
    {
        $profile = $this->extensionProfileService->fromPlannedFiles(['Configuration/TypoScript/setup.typoscript']);

        self::assertSame(['composerAudit', 'typoScriptLint'], $this->toolchainGenerator->getEnabledTools($profile));
        self::assertFalse($this->toolchainGenerator->needsNode($profile));
        self::assertSame(
            [
                'post-install-cmd' => ['.Build/bin/qa link'],
                'post-update-cmd'  => ['.Build/bin/qa link'],
                'test:all'         => [],
            ],
            $this->toolchainGenerator->getManagedComposerScripts($profile)
        );
    }

    #[Test]
    public function yamlFilesEnableTheYamlLinter(): void
    {
        $profile = $this->extensionProfileService->fromPlannedFiles(['Configuration/Services.yaml']);

        self::assertSame(['composerAudit', 'yamlLint'], $this->toolchainGenerator->getEnabledTools($profile));
    }
}
