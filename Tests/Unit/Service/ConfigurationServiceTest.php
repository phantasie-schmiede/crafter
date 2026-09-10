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
use PSBits\Crafter\Event\ModifyConfigurationEvent;
use PSBits\Crafter\Service\ConfigurationService;
use PSBits\Crafter\Tests\Fixtures\CallbackEventDispatcher;
use PSBits\Crafter\Tests\Unit\AbstractCrafterTestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;

/**
 * Class ConfigurationServiceTest
 *
 * @package PSBits\Crafter\Tests\Unit\Service
 */
final class ConfigurationServiceTest extends AbstractCrafterTestCase
{
    #[Test]
    public function eventIsTopmostLayerAndIsTrackedAsOrigin(): void
    {
        $this->writeProjectConfiguration("crafter:\n  defaults:\n    author: 'Project Author'\n");

        $dispatcher = new CallbackEventDispatcher();
        $dispatcher->addListener(static function(object $event): void {
            if ($event instanceof ModifyConfigurationEvent) {
                $event->addOverrides(['defaults' => ['author' => 'Event Author']]);
            }
        });
        $configurationService = new ConfigurationService($dispatcher, new YamlFileLoader(new NullLogger()));

        self::assertSame('Event Author', $configurationService->getValue('defaults.author'));
        self::assertSame('event', $configurationService->getOrigins()['defaults.author']);
        self::assertArrayHasKey('event', $configurationService->getLayers());
    }

    #[Test]
    public function listsAreReplacedCompletely(): void
    {
        $this->writeProjectConfiguration("crafter:\n  audit:\n    languages:\n      - 'fr'\n");

        self::assertSame(['fr'], $this->createConfigurationService()->getValue('audit.languages'));
    }

    #[Test]
    public function mapsAreMergedRecursively(): void
    {
        $this->writeProjectConfiguration("crafter:\n  paths:\n    model: 'Classes/Model/{name}.php'\n");
        $configurationService = $this->createConfigurationService();

        self::assertSame('Classes/Model/{name}.php', $configurationService->getValue('paths.model'));
        // Untouched siblings of the overridden key must survive the merge.
        self::assertSame(
            'Classes/Domain/Repository/{name}Repository.php',
            $configurationService->getValue('paths.repository')
        );
    }

    #[Test]
    public function originsExplainTheWinningLayer(): void
    {
        $this->writeProjectConfiguration("crafter:\n  paths:\n    model: 'Classes/Model/{name}.php'\n");
        $origins = $this->createConfigurationService()->getOrigins();

        self::assertSame('project', $origins['paths.model']);
        self::assertSame('crafter (default)', $origins['paths.repository']);
    }

    #[Test]
    public function runtimeOverridesBeatFileLayers(): void
    {
        $this->writeProjectConfiguration("crafter:\n  defaults:\n    author: 'Project Author'\n");
        $configurationService = $this->createConfigurationService();
        $configurationService->setRuntimeOverrides(['defaults' => ['author' => 'CLI Author']]);

        self::assertSame('CLI Author', $configurationService->getValue('defaults.author'));
        self::assertSame('cli', $configurationService->getOrigins()['defaults.author']);
    }

    #[Test]
    public function shippedDefaultsAreLoaded(): void
    {
        self::assertSame(
            'Classes/Domain/Model/{name}.php',
            $this->configurationService->getValue('paths.model')
        );
    }

    #[Test]
    public function unknownPathReturnsTheGivenDefault(): void
    {
        self::assertSame(
            'fallback',
            $this->configurationService->getValue('paths.doesNotExist', null, 'fallback')
        );
    }

    /**
     * A fresh instance is required whenever a layer is written after setUp, because the merge result is cached.
     */
    private function createConfigurationService(): ConfigurationService
    {
        return new ConfigurationService($this->eventDispatcher, new YamlFileLoader(new NullLogger()));
    }
}
