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
 * Class ToolchainGeneratorTest
 *
 * Verifies the tool selection and the assembled composer/package maps against real profiles. The expected values
 * mirror the shipped default tool configuration, so every assertion also pins the configuration itself.
 *
 * @package PSBits\Crafter\Tests\Unit\Service
 */
final class ToolchainGeneratorTest extends AbstractCrafterTestCase
{
    #[Test]
    public function emptyProfileEnablesOnlyTheAlwaysOnTools(): void
    {
        $profile = $this->extensionProfileService->fromPlannedFiles([]);

        self::assertSame(['composerAudit'], $this->toolchainGenerator->getEnabledTools($profile));
    }

    #[Test]
    public function phpProfileEnablesThePhpToolsAndAssemblesTheirScripts(): void
    {
        $profile = $this->extensionProfileService->fromPlannedFiles(['Classes/Domain/Model/Product.php']);

        self::assertSame(
            ['composerAudit', 'phpCsFixer', 'phpStan'],
            $this->toolchainGenerator->getEnabledTools($profile)
        );
        self::assertSame(
            [
                'analyze:php'       => 'phpstan analyse --configuration=Build/Quality/phpstan.neon.dist --no-progress',
                'audit:composer'    => 'composer audit --abandoned=report',
                'fix:all'           => ['@fix:php'],
                'fix:php'           => 'php-cs-fixer fix --config=Build/Quality/.php-cs-fixer.dist.php --verbose',
                'lint:all'          => ['@analyze:php', '@audit:composer', '@lint:php', '@validate:composer'],
                'lint:php'          => 'php-cs-fixer fix --config=Build/Quality/.php-cs-fixer.dist.php --dry-run --diff --verbose',
                'post-install-cmd'  => ['cp Build/GitHooks/pre-commit .git/hooks/pre-commit'],
                'validate:composer' => 'composer validate --no-check-publish',
            ],
            $this->toolchainGenerator->getManagedComposerScripts($profile)
        );
        self::assertSame(
            [
                'Extension/Quality/PhpCsFixer',
                'Extension/Quality/PhpCsFixerClassBlockSeparation',
                'Extension/Quality/PhpCsFixerPropertyNamesAlignment',
                'Extension/Quality/PhpStan',
            ],
            array_keys($this->toolchainGenerator->getConfigFiles($profile))
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
                'roave/security-advisories'     => 'dev-latest',
                'saschaegerer/phpstan-typo3'    => '^2.1',
            ],
            $this->toolchainGenerator->getManagedComposerRequireDev($profile)
        );
    }

    #[Test]
    public function htmlProfileTurnsTheClassLinterAndPrettierOn(): void
    {
        $profile   = $this->extensionProfileService->fromPlannedFiles(['Resources/Private/Templates/Product/List.html']);
        $enabled   = $this->toolchainGenerator->getEnabledTools($profile);
        $variables = $this->toolchainGenerator->getVariables($profile);

        self::assertSame(['classLint', 'composerAudit', 'prettier'], $enabled);
        self::assertTrue($this->toolchainGenerator->needsNode($profile));
        self::assertStringContainsString('Setup Node.js', $variables['workflowNode']);
        self::assertSame(
            [
                'fix:classes'  => 'node Build/Quality/lint-class-attributes.mjs',
                'fix:htmlxml'  => 'prettier --config Build/Quality/prettier.config.mjs --ignore-path Build/Quality/.prettierignore --write "**/*.{html,xml,xlf}"',
                'lint:classes' => 'node Build/Quality/lint-class-attributes.mjs --dry-run',
                'lint:htmlxml' => 'prettier --config Build/Quality/prettier.config.mjs --ignore-path Build/Quality/.prettierignore --check "**/*.{html,xml,xlf}"',
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
        self::assertContains(
            'Extension/Quality/LintClassAttributes',
            array_keys($this->toolchainGenerator->getConfigFiles($profile))
        );
    }

    #[Test]
    public function typoScriptProfileKeepsNodeOutOfTheWorkflow(): void
    {
        $profile   = $this->extensionProfileService->fromPlannedFiles(['Configuration/TypoScript/setup.typoscript']);
        $variables = $this->toolchainGenerator->getVariables($profile);

        self::assertSame(['composerAudit', 'typoScriptLint'], $this->toolchainGenerator->getEnabledTools($profile));
        self::assertFalse($this->toolchainGenerator->needsNode($profile));
        self::assertSame('', $variables['workflowNode']);
        self::assertSame(
            [
                        'audit:composer'    => 'composer audit --abandoned=report',
                        'lint:all'          => ['@audit:composer', '@lint:typoscript', '@validate:composer'],
                        'lint:typoscript'   => 'typoscript-lint --config=Build/Quality/typoscript-lint.yml --ansi Configuration/TypoScript',
                        'post-install-cmd'  => ['cp Build/GitHooks/pre-commit .git/hooks/pre-commit'],
                        'validate:composer' => 'composer validate --no-check-publish',
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

    #[Test]
    public function agentBulletsReflectTheEnabledTools(): void
    {
        $profile   = $this->extensionProfileService->fromPlannedFiles(['Resources/Private/Templates/Product/List.html']);
        $variables = $this->toolchainGenerator->getVariables($profile);

        self::assertStringContainsString('- `lint:classes`', $variables['agentsToolChecks']);
        self::assertStringContainsString('- `lint:htmlxml`', $variables['agentsToolChecks']);
        self::assertStringContainsString('- `audit:composer`', $variables['agentsToolChecks']);
        self::assertStringNotContainsString('lint:php', $variables['agentsToolChecks']);
        self::assertStringContainsString('Class attribute lint', $variables['workflowSteps']);
        self::assertStringContainsString('HTML/XML/XLF lint', $variables['workflowSteps']);
        self::assertStringNotContainsString('PHP CS check', $variables['workflowSteps']);
        self::assertStringNotContainsString('TypoScript lint', $variables['workflowSteps']);
    }

    #[Test]
    public function theSupportMatrixAndTerPublishJobAreRenderedForTheWorkflow(): void
    {
        $profile   = $this->extensionProfileService->fromPlannedFiles([]);
        $variables = $this->toolchainGenerator->getVariables($profile, self::EXTENSION_KEY);

        self::assertStringContainsString('support-matrix:', $variables['workflowMatrix']);
        self::assertStringContainsString('lowest: true', $variables['workflowMatrix']);
        self::assertStringContainsString("php: '8.3'", $variables['workflowMatrix']);
        self::assertStringContainsString("typo3: '13.4'", $variables['workflowMatrix']);
        self::assertStringContainsString("php: '8.5'", $variables['workflowMatrix']);
        self::assertStringContainsString("typo3: '14.3'", $variables['workflowMatrix']);
        self::assertStringContainsString('--prefer-lowest --prefer-stable', $variables['workflowMatrix']);
        self::assertStringContainsString('needs.support-matrix.result', $variables['workflowPublish']);
        self::assertStringContainsString('needs: [quality, support-matrix]', $variables['workflowPublish']);
        self::assertStringContainsString('publish-to-ter:', $variables['workflowPublish']);
        self::assertStringContainsString(
            '$_EXTKEY="' . self::EXTENSION_KEY . '"',
            $variables['workflowPublish']
        );
    }

    #[Test]
    public function theTerPublishJobDependsOnlyOnQualityWithoutASupportMatrix(): void
    {
        $this->writeProjectConfiguration(<<<'YAML'
            crafter:
              ci:
                matrix: []
            YAML);
        $profile   = $this->extensionProfileService->fromPlannedFiles([]);
        $variables = $this->toolchainGenerator->getVariables($profile, self::EXTENSION_KEY);

        self::assertSame('', $variables['workflowMatrix']);
        self::assertStringContainsString('needs: [quality]', $variables['workflowPublish']);
        self::assertStringNotContainsString('support-matrix', $variables['workflowPublish']);
    }

    #[Test]
    public function theSupportMatrixAndTerPublishCanBeDisabled(): void
    {
        $this->writeProjectConfiguration(<<<'YAML'
            crafter:
              ci:
                matrix: []
                publishToTer: false
            YAML);
        $profile   = $this->extensionProfileService->fromPlannedFiles([]);
        $variables = $this->toolchainGenerator->getVariables($profile, self::EXTENSION_KEY);

        self::assertSame('', $variables['workflowMatrix']);
        self::assertSame('', $variables['workflowPublish']);
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
}
