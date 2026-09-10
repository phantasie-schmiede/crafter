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
use PSBits\Crafter\Data\AuditFinding;
use PSBits\Crafter\Data\ControllerBlueprint;
use PSBits\Crafter\Data\ExtensionBlueprint;
use PSBits\Crafter\Data\ModelBlueprint;
use PSBits\Crafter\Data\PropertyDefinition;
use PSBits\Crafter\Enum\AuditSeverity;
use PSBits\Crafter\Exception\CrafterException;
use PSBits\Crafter\Service\ExtensionAuditor;
use PSBits\Crafter\Service\ExtensionGenerator;
use PSBits\Crafter\Tests\Unit\AbstractGeneratorTestCase;

/**
 * Class ExtensionAuditorTest
 *
 * Builds a complete extension with the generators and then breaks it on purpose, so every category is verified
 * against output the generators really produce instead of a hand written fixture.
 *
 * @package PSBits\Crafter\Tests\Unit\Service
 */
final class ExtensionAuditorTest extends AbstractGeneratorTestCase
{
    private ExtensionAuditor $extensionAuditor;

    private ExtensionGenerator $extensionGenerator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extensionAuditor   = new ExtensionAuditor($this->configurationService, $this->pathResolver);
        $this->extensionGenerator = new ExtensionGenerator(
            $this->configurationService,
            $this->fileWriter,
            $this->pathResolver,
            $this->skeletonRenderer
        );
    }

    #[Test]
    public function completeExtensionHasNoFindings(): void
    {
        $this->generateExtension();

        self::assertSame([], $this->extensionAuditor->audit(self::EXTENSION_KEY));
    }

    #[Test]
    public function missingLanguageFileIsReported(): void
    {
        $this->generateExtension();
        unlink($this->getExtensionFilePath('Resources/Private/Language/Frontend/Product/de.list.xlf'));

        self::assertCount(1, $this->auditCategory('language'));
    }

    #[Test]
    public function missingRepositoryIsReported(): void
    {
        $this->generateExtension();
        unlink($this->getExtensionFilePath('Classes/Domain/Repository/ProductRepository.php'));
        $findings = $this->auditCategory('model');

        self::assertCount(1, $findings);
        self::assertSame(AuditSeverity::warning, $findings[0]->severity);
        self::assertStringContainsString('crafter:make:repository', $findings[0]->hint);
    }

    #[Test]
    public function missingRequiredFileIsReported(): void
    {
        $this->generateExtension();
        unlink($this->getExtensionFilePath('ext_emconf.php'));
        $findings = $this->auditCategory('structure');

        self::assertCount(1, $findings);
        self::assertSame(AuditSeverity::error, $findings[0]->severity);
        self::assertSame('ext_emconf.php', $findings[0]->path);
    }

    #[Test]
    public function missingTemplateIsReported(): void
    {
        $this->generateExtension();
        unlink($this->getExtensionFilePath('Resources/Private/Templates/Product/List.html'));
        $findings = $this->auditCategory('controller');

        self::assertCount(1, $findings);
        self::assertSame(AuditSeverity::warning, $findings[0]->severity);
        self::assertStringContainsString('List.html', $findings[0]->path);
    }

    #[Test]
    public function orphanedTemplateIsReported(): void
    {
        $this->generateExtension();
        copy(
            $this->getExtensionFilePath('Resources/Private/Templates/Product/List.html'),
            $this->getExtensionFilePath('Resources/Private/Templates/Product/Archive.html')
        );
        $findings = $this->auditCategory('orphan');

        self::assertCount(1, $findings);
        self::assertStringContainsString('Archive.html', $findings[0]->path);
    }

    #[Test]
    public function unknownExtensionThrows(): void
    {
        $this->expectException(CrafterException::class);
        $this->expectExceptionCode(1757426420);

        $this->extensionAuditor->audit(self::EXTENSION_KEY);
    }

    #[Test]
    public function unregisteredPluginIsReported(): void
    {
        $this->generateExtension();
        $path   = $this->getExtensionFilePath('Classes/Data/ExtensionInformation.php');
        $source = (string)file_get_contents($path);
        file_put_contents($path, str_replace('ProductController::class', 'null', $source));
        $findings = $this->auditCategory('registration');

        self::assertCount(1, $findings);
        self::assertStringContainsString('crafter:make:controller', $findings[0]->hint);
    }

    /**
     * @return AuditFinding[]
     */
    private function auditCategory(string $category): array
    {
        return $this->extensionAuditor->audit(self::EXTENSION_KEY, [$category]);
    }

    /**
     * Creates an extension that satisfies every audit category: base files, a model, its repository and a controller
     * including templates, language files and a registered plugin.
     */
    private function generateExtension(): void
    {
        $this->extensionGenerator->generate(
            new ExtensionBlueprint(
                extensionKey: self::EXTENSION_KEY,
                extensionName: 'DemoShop',
                vendor: 'PSBits',
                namespace: 'PSBits\DemoShop',
                composerName: 'psbits/demo-shop',
                title: 'Demo Shop',
                packageTitle: 'PSBits Demo Shop',
                description: 'Test extension.',
                author: 'Daniel Ablass',
                authorEmail: 'dn@phantasie-schmiede.de',
                license: 'GPL-3.0-or-later',
                category: 'plugin',
                state: 'alpha',
                version: '0.1.0',
                gitHubOrganization: 'phantasie-schmiede'
            ),
            ['core']
        );
        $properties = [new PropertyDefinition(name: 'title', type: 'string', required: true)];
        $this->modelGenerator->generate(new ModelBlueprint(
            extensionKey: self::EXTENSION_KEY,
            name: 'Product',
            properties: $properties
        ));
        $this->repositoryGenerator->generate(self::EXTENSION_KEY, 'Product');
        $this->controllerGenerator->generate(new ControllerBlueprint(
            extensionKey: self::EXTENSION_KEY,
            name: 'Product',
            modelName: 'Product',
            actions: ['list', 'show', 'new', 'create', 'edit', 'update', 'delete'],
            properties: $properties
        ));
    }
}
