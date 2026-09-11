<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Tests\Unit;

use PSBits\Crafter\Data\ExtensionBlueprint;
use PSBits\Crafter\Service\BackendControllerGenerator;
use PSBits\Crafter\Service\ClassGenerator;
use PSBits\Crafter\Service\CodeInjector;
use PSBits\Crafter\Service\ControllerGenerator;
use PSBits\Crafter\Service\ExtensionGenerator;
use PSBits\Crafter\Service\FieldTypeRegistry;
use PSBits\Crafter\Service\LanguageFileGenerator;
use PSBits\Crafter\Service\ModelGenerator;
use PSBits\Crafter\Service\ModuleRegistrar;
use PSBits\Crafter\Service\PluginRegistrar;
use PSBits\Crafter\Service\RepositoryGenerator;

/**
 * Class AbstractGeneratorTestCase
 *
 * Adds the generator layer on top of the base service graph. The services are wired manually instead of via the
 * container, so the tests stay independent of a running TYPO3 installation.
 *
 * @package PSBits\Crafter\Tests\Unit
 */
abstract class AbstractGeneratorTestCase extends AbstractCrafterTestCase
{
    protected BackendControllerGenerator $backendControllerGenerator;

    protected ClassGenerator $classGenerator;

    protected ControllerGenerator $controllerGenerator;

    protected ExtensionGenerator $extensionGenerator;

    protected LanguageFileGenerator $languageFileGenerator;

    protected ModelGenerator $modelGenerator;

    protected RepositoryGenerator $repositoryGenerator;

    protected function setUp(): void
    {
        parent::setUp();

        $codeInjector         = new CodeInjector();
        $this->classGenerator = new ClassGenerator(
            $this->configurationService,
            $this->fileWriter,
            $this->pathResolver,
            $this->skeletonRenderer
        );
        $this->extensionGenerator = new ExtensionGenerator(
            $this->configurationService,
            $this->fileWriter,
            $this->pathResolver,
            $this->skeletonRenderer
        );
        $this->languageFileGenerator = new LanguageFileGenerator(
            $this->configurationService,
            $this->fileWriter,
            $this->pathResolver,
            $this->skeletonRenderer
        );
        $this->modelGenerator = new ModelGenerator(
            $this->configurationService,
            new FieldTypeRegistry($this->configurationService),
            $this->fileWriter,
            $this->pathResolver,
            $this->skeletonRenderer
        );
        $this->repositoryGenerator = new RepositoryGenerator(
            $this->configurationService,
            $this->fileWriter,
            $this->pathResolver,
            $this->skeletonRenderer
        );
        $this->controllerGenerator = new ControllerGenerator(
            $this->configurationService,
            $this->fileWriter,
            $this->languageFileGenerator,
            $this->pathResolver,
            new PluginRegistrar(
                $codeInjector,
                $this->configurationService,
                $this->fileWriter,
                $this->pathResolver,
                $this->skeletonRenderer
            ),
            $this->skeletonRenderer
        );
        $this->backendControllerGenerator = new BackendControllerGenerator(
            $this->classGenerator,
            $this->configurationService,
            $this->fileWriter,
            $this->languageFileGenerator,
            new ModuleRegistrar(
                $this->classGenerator,
                $codeInjector,
                $this->configurationService,
                $this->fileWriter,
                $this->pathResolver,
                $this->skeletonRenderer
            ),
            $this->pathResolver,
            $this->skeletonRenderer
        );
    }

    /**
     * Generates the `core` file group, which every follow-up generator expects to be present, most notably
     * Classes/Data/ExtensionInformation.php.
     */
    protected function generateBaseExtension(): void
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
    }
}
