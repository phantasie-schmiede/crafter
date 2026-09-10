<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Tests\Unit;

use PHPUnit\Framework\MockObject\MockObject;
use PSBits\Crafter\Service\ConfigurationService;
use PSBits\Crafter\Service\FileWriter;
use PSBits\Crafter\Service\PathResolver;
use PSBits\Crafter\Service\SkeletonRenderer;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\EventDispatcher\NoopEventDispatcher;
use TYPO3\CMS\Core\Package\Exception\UnknownPackageException;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Class AbstractCrafterTestCase
 *
 * Wires the real service graph against a throwaway project directory. Only the PackageManager is mocked, because
 * everything else has to behave exactly as it does in a running installation: the configuration is read from the
 * shipped Crafter.yaml and the stubs are read from the shipped skeleton directory.
 *
 * The generated extension ends up in <tmp>/packages/<extensionKey>/, which is what the `extensionRootPath` default
 * resolves to once the project path points to the temporary directory.
 *
 * @package PSBits\Crafter\Tests\Unit
 */
abstract class AbstractCrafterTestCase extends UnitTestCase
{
    protected const string EXTENSION_KEY = 'demo_shop';

    protected ConfigurationService $configurationService;

    protected EventDispatcherInterface $eventDispatcher;

    protected FileWriter $fileWriter;

    /** @var PackageManager&MockObject */
    protected PackageManager $packageManager;

    protected PathResolver $pathResolver;

    protected string $projectPath = '';

    protected SkeletonRenderer $skeletonRenderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectPath = $this->createTemporaryDirectory();
        $this->initializeEnvironment();
        $this->initializePackageManager();

        $this->eventDispatcher      = new NoopEventDispatcher();
        $this->configurationService = new ConfigurationService(
            $this->eventDispatcher,
            new YamlFileLoader(new NullLogger())
        );
        $this->pathResolver     = new PathResolver($this->configurationService, $this->packageManager);
        $this->fileWriter       = new FileWriter($this->eventDispatcher);
        $this->skeletonRenderer = new SkeletonRenderer(
            $this->configurationService,
            $this->eventDispatcher,
            $this->pathResolver
        );
        $this->fileWriter->reset();
    }

    protected function tearDown(): void
    {
        if ('' !== $this->projectPath && is_dir($this->projectPath)) {
            GeneralUtility::rmdir($this->projectPath, true);
        }

        parent::tearDown();
    }

    /**
     * Compares a generated file with its fixture. Set CRAFTER_UPDATE_FIXTURES=1 to rewrite the fixtures after an
     * intended change of the generated output. Always review the resulting diff.
     */
    protected function assertFileMatchesFixture(string $relativePath, string $fixtureName): void
    {
        $generatedPath = $this->getExtensionFilePath($relativePath);
        self::assertFileExists($generatedPath, sprintf('File "%s" has not been generated.', $relativePath));
        $generatedContent = self::normalizeVolatileValues((string)file_get_contents($generatedPath));
        $fixturePath      = self::getFixturePath($fixtureName);

        if ('1' === getenv('CRAFTER_UPDATE_FIXTURES')) {
            GeneralUtility::mkdir_deep(dirname($fixturePath));
            GeneralUtility::writeFile($fixturePath, $generatedContent, true);
        }

        self::assertFileExists($fixturePath, sprintf('Fixture "%s" is missing.', $fixtureName));
        self::assertSame(
            self::normalizeVolatileValues((string)file_get_contents($fixturePath)),
            $generatedContent,
            sprintf('Generated file "%s" differs from fixture "%s".', $relativePath, $fixtureName)
        );
    }

    /**
     * Absolute path inside the generated extension.
     */
    protected function getExtensionFilePath(string $relativePath): string
    {
        return $this->projectPath . '/packages/' . self::EXTENSION_KEY . '/' . ltrim($relativePath, '/');
    }

    protected function getFixtureContent(string $name): string
    {
        $path = self::getFixturePath($name);

        self::assertFileExists($path, sprintf('Fixture "%s" is missing.', $name));

        return (string)file_get_contents($path);
    }

    /**
     * Writes a project wide configuration layer, which is the layer a project uses to override the defaults.
     */
    protected function writeProjectConfiguration(string $yaml): void
    {
        GeneralUtility::mkdir_deep($this->projectPath . '/config');
        GeneralUtility::writeFile($this->projectPath . '/config/crafter.yaml', $yaml, true);
    }

    private function createTemporaryDirectory(): string
    {
        $path = sys_get_temp_dir() . '/crafter-test-' . bin2hex(random_bytes(8));
        GeneralUtility::mkdir_deep($path);

        return $path;
    }

    private static function getFixturePath(string $name): string
    {
        return dirname(__DIR__) . '/Fixtures/Expected/' . $name;
    }

    /**
     * Replaces values that change with every run, currently the generation date of XLIFF files and the copyright
     * year of PHP files.
     */
    private static function normalizeVolatileValues(string $content): string
    {
        return (string)preg_replace(
            ['/date="[^"]*"/', '/(\(c\) )\d{4}/'],
            ['date="@@date@@"', '$1@@year@@'],
            $content
        );
    }

    private function initializeEnvironment(): void
    {
        Environment::initialize(
            Environment::getContext(),
            true,
            true,
            $this->projectPath,
            $this->projectPath . '/public',
            $this->projectPath . '/var',
            $this->projectPath . '/config',
            Environment::getCurrentScript(),
            Environment::isWindows() ? 'WINDOWS' : 'UNIX'
        );
    }

    private function initializePackageManager(): void
    {
        $crafterPath = dirname(__DIR__, 2) . '/';
        $package     = $this->createMock(PackageInterface::class);
        $package->method('getPackagePath')->willReturn($crafterPath);

        $this->packageManager = $this->createMock(PackageManager::class);
        $this->packageManager->method('isPackageActive')->willReturnCallback(
            static fn(string $packageKey): bool => 'crafter' === $packageKey
        );
        $this->packageManager->method('resolvePackagePath')->willReturnCallback(
            static function(string $path) use ($crafterPath): string {
                if (!str_starts_with($path, 'EXT:crafter/')) {
                    throw new UnknownPackageException(
                        sprintf('Path "%s" cannot be resolved in this test.', $path),
                        1757426431
                    );
                }

                return str_replace('EXT:crafter/', $crafterPath, $path);
            }
        );
        $this->packageManager->method('getPackage')->willReturnCallback(
            static function(string $packageKey) use ($package): PackageInterface {
                if ('crafter' === $packageKey) {
                    return $package;
                }

                throw new UnknownPackageException(
                    sprintf('Package "%s" is not available in this test.', $packageKey),
                    1757426430
                );
            }
        );

        ExtensionManagementUtility::setPackageManager($this->packageManager);
    }
}
