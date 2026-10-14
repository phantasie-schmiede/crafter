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
use PSBits\Crafter\Data\ClassBlueprint;
use PSBits\Crafter\Data\ControllerBlueprint;
use PSBits\Crafter\Data\ModelBlueprint;
use PSBits\Crafter\Data\PropertyDefinition;
use PSBits\Crafter\Tests\Unit\AbstractGeneratorTestCase;

/**
 * Class GeneratorTest
 *
 * Golden master tests: every generator writes into a throwaway project and the result is compared byte by byte with
 * a reviewed fixture. Run the suite with CRAFTER_UPDATE_FIXTURES=1 after an intended change of the output.
 *
 * @package PSBits\Crafter\Tests\Unit\Service
 */
final class GeneratorTest extends AbstractGeneratorTestCase
{
    #[Test]
    public function backendControllerIsGeneratedWithTemplatesAndLanguageFiles(): void
    {
        $this->generateBaseExtension();
        $this->backendControllerGenerator->generate(
            self::EXTENSION_KEY,
            'Import',
            ['index', 'run'],
            ['PSBits\DemoShop\Domain\Repository\ProductRepository'],
            'import'
        );

        $this->assertFileMatchesFixture(
            'Classes/Controller/Backend/ImportController.php',
            'Backend/ImportController.php'
        );
        $this->assertFileMatchesFixture(
            'Resources/Private/Templates/Backend/Import/Index.html',
            'Backend/Index.html'
        );
        $this->assertFileMatchesFixture(
            'Resources/Private/Language/Backend/Modules/demoshop_import.xlf',
            'Backend/demoshop_import.xlf'
        );
        // TYPO3 only picks up modules that are returned by this file.
        $this->assertFileMatchesFixture(
            'Configuration/Backend/Modules.php',
            'Backend/Modules.php'
        );
        $this->assertFileMatchesFixture(
            'Classes/Data/ExtensionInformation.php',
            'Backend/ExtensionInformation.php'
        );
    }

    #[Test]
    public function controllerIsGeneratedWithCrudActionsTemplatesAndLanguageFiles(): void
    {
        $this->controllerGenerator->generate(new ControllerBlueprint(
            extensionKey: self::EXTENSION_KEY,
            name: 'Product',
            modelName: 'Product',
            actions: ['list', 'show', 'new', 'create', 'edit', 'update', 'delete'],
            properties: self::getProperties(),
            withPlugin: false
        ));

        $this->assertFileMatchesFixture('Classes/Controller/ProductController.php', 'ProductController.php');
        $this->assertFileMatchesFixture('Resources/Private/Templates/Product/List.html', 'Template/List.html');
        $this->assertFileMatchesFixture('Resources/Private/Templates/Product/Show.html', 'Template/Show.html');
        $this->assertFileMatchesFixture('Resources/Private/Templates/Product/New.html', 'Template/New.html');
        $this->assertFileMatchesFixture('Resources/Private/Templates/Product/Edit.html', 'Template/Edit.html');
        $this->assertFileMatchesFixture(
            'Resources/Private/Partials/FormFields/Product.html',
            'Template/FormFields.html'
        );
        $this->assertFileMatchesFixture(
            'Resources/Private/Language/Frontend/Product/list.xlf',
            'Language/list.xlf'
        );
        $this->assertFileMatchesFixture(
            'Resources/Private/Language/Frontend/Product/de.list.xlf',
            'Language/de.list.xlf'
        );
    }

    #[Test]
    public function eventListenerIsGenerated(): void
    {
        $this->classGenerator->generate(new ClassBlueprint(
            extensionKey: self::EXTENSION_KEY,
            name: 'ProductUpdate',
            type: 'eventListener',
            imports: ['TYPO3\CMS\Core\Utility\GeneralUtility']
        ));

        $this->assertFileMatchesFixture(
            'Classes/EventListener/ProductUpdate.php',
            'ProductUpdate.php'
        );
    }

    #[Test]
    public function modelIsGeneratedWithEveryRelevantPropertyType(): void
    {
        $this->modelGenerator->generate(new ModelBlueprint(
            extensionKey: self::EXTENSION_KEY,
            name: 'Product',
            properties: self::getProperties()
        ));

        $this->assertFileMatchesFixture('Classes/Domain/Model/Product.php', 'Product.php');
    }

    #[Test]
    public function extensionIsGeneratedWithTheQualityWorkflow(): void
    {
        $this->generateBaseExtension(['core', 'typoScript', 'quality', 'ci']);

        $this->assertFileMatchesFixture('.github/workflows/quality.yml', 'Workflow/Quality.yml');
    }

    #[Test]
    public function moduleConfigurationImportsAreSortedForAnyVendor(): void
    {
        $this->generateBaseExtension();

        // A vendor sorting after 'PSBits\Foundation' must not break the import order.
        file_put_contents(
            $this->getExtensionFilePath('composer.json'),
            (string)json_encode(['autoload' => ['psr-4' => ['Zeta\DemoShop\\' => 'Classes/']]])
        );
        $this->backendControllerGenerator->generate(self::EXTENSION_KEY, 'Import', ['index'], [], 'import');

        self::assertStringContainsString(
            implode("\n", [
                'use PSBits\Foundation\Service\Configuration\ModuleService;',
                'use TYPO3\CMS\Core\Utility\GeneralUtility;',
                'use Zeta\DemoShop\Data\ExtensionInformation;',
            ]),
            (string)file_get_contents($this->getExtensionFilePath('Configuration/Backend/Modules.php'))
        );
    }

    #[Test]
    public function repositoryIsGenerated(): void
    {
        $this->repositoryGenerator->generate(self::EXTENSION_KEY, 'Product');

        $this->assertFileMatchesFixture('Classes/Domain/Repository/ProductRepository.php', 'ProductRepository.php');
    }

    #[Test]
    public function serviceIsGeneratedWithInjectedDependencies(): void
    {
        $this->classGenerator->generate(new ClassBlueprint(
            extensionKey: self::EXTENSION_KEY,
            name: 'Price',
            type: 'service',
            dependencies: [
                'PSBits\DemoShop\Domain\Repository\ProductRepository',
                'TYPO3\CMS\Core\Context\Context',
            ]
        ));

        $this->assertFileMatchesFixture('Classes/Service/PriceService.php', 'PriceService.php');
    }

    #[Test]
    public function viewHelperIsGeneratedInASubDirectory(): void
    {
        $this->classGenerator->generate(new ClassBlueprint(
            extensionKey: self::EXTENSION_KEY,
            name: 'Price',
            type: 'viewHelper',
            subPath: 'Format'
        ));

        $this->assertFileMatchesFixture(
            'Classes/ViewHelpers/Format/PriceViewHelper.php',
            'PriceViewHelper.php'
        );
    }

    /**
     * Covers every rendering branch of the model generator: scalars, dates, relations and enums.
     *
     * @return PropertyDefinition[]
     */
    private static function getProperties(): array
    {
        return [
            new PropertyDefinition(name: 'title', type: 'string', required: true),
            new PropertyDefinition(name: 'description', type: 'text'),
            new PropertyDefinition(name: 'price', type: 'decimal'),
            new PropertyDefinition(name: 'active', type: 'boolean'),
            new PropertyDefinition(name: 'releaseDate', type: 'date'),
            new PropertyDefinition(
                name: 'category',
                type: 'relationSingle',
                linkedClass: 'PSBits\DemoShop\Domain\Model\Category'
            ),
            // The console passes short names, so both notations have to work.
            new PropertyDefinition(
                name: 'tags',
                type: 'relationMultiple',
                linkedClass: 'Tag',
                foreignField: 'product'
            ),
        ];
    }
}
