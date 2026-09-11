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
use PSBits\Crafter\Exception\CrafterException;
use PSBits\Crafter\Tests\Unit\AbstractCrafterTestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function dirname;

/**
 * Class PathResolverTest
 *
 * @package PSBits\Crafter\Tests\Unit\Service
 */
final class PathResolverTest extends AbstractCrafterTestCase
{
    #[Test]
    public function candidatesAreOrderedByDescendingPriority(): void
    {
        $candidates = $this->pathResolver->getSkeletonCandidates('Php/Model', self::EXTENSION_KEY);

        // Priority 20 (EXT:{targetExtension}) is dropped, because the target extension does not exist yet.
        self::assertSame([10, 0], array_column($candidates, 'priority'));
        self::assertSame('FILE:config/Crafter/Skeletons/', $candidates[0]['root']);
        self::assertTrue($candidates[1]['exists']);
    }

    #[Test]
    public function collapsesEmptyOptionalPathSegments(): void
    {
        self::assertSame(
            $this->getExtensionFilePath('Classes/ViewHelpers/TeaserViewHelper.php'),
            $this->pathResolver->resolveTargetPath('viewHelper', self::EXTENSION_KEY, [
                'name' => 'Teaser',
                'path' => '',
            ])
        );
    }

    #[Test]
    public function extensionPathFallsBackToTheConfiguredExtensionRootPath(): void
    {
        self::assertSame(
            $this->projectPath . '/packages/' . self::EXTENSION_KEY . '/',
            $this->pathResolver->resolveExtensionPath(self::EXTENSION_KEY)
        );
    }

    #[Test]
    public function namespaceIsReadFromComposerJson(): void
    {
        $path = $this->getExtensionFilePath('composer.json');
        GeneralUtility::mkdir_deep(dirname($path));
        GeneralUtility::writeFile(
            $path,
            (string)json_encode(['autoload' => ['psr-4' => ['Acme\Shop\\' => 'Classes/']]]),
            true
        );

        self::assertSame('Acme\Shop', $this->pathResolver->resolveNamespace(self::EXTENSION_KEY));
    }

    #[Test]
    public function namespaceFallsBackToTheVendorAndExtensionName(): void
    {
        self::assertSame(
            'PSBits\DemoShop',
            $this->pathResolver->resolveNamespace(self::EXTENSION_KEY, 'PSBits')
        );
    }

    #[Test]
    public function projectSkeletonWinsOverTheShippedOne(): void
    {
        $path = $this->projectPath . '/config/Crafter/Skeletons/Php/Model.stub';
        GeneralUtility::mkdir_deep(dirname($path));
        GeneralUtility::writeFile($path, 'overridden', true);

        self::assertSame($path, $this->pathResolver->resolveSkeleton('Php/Model', self::EXTENSION_KEY));
    }

    #[Test]
    public function shippedSkeletonIsResolvedWhenNothingOverridesIt(): void
    {
        self::assertSame(
            dirname(__DIR__, 3) . '/Resources/Private/Skeletons/Php/Model.stub',
            $this->pathResolver->resolveSkeleton('Php/Model', self::EXTENSION_KEY)
        );
    }

    #[Test]
    public function targetPathReplacesAllPlaceholders(): void
    {
        self::assertSame(
            $this->getExtensionFilePath('Resources/Private/Language/Frontend/Product/list.xlf'),
            $this->pathResolver->resolveTargetPath('languageFrontend', self::EXTENSION_KEY, [
                'action'     => 'list',
                'controller' => 'Product',
            ])
        );
    }

    #[Test]
    public function unconfiguredPathTypeThrows(): void
    {
        $this->expectException(CrafterException::class);
        $this->expectExceptionCode(1757426404);

        $this->pathResolver->resolveTargetPath('doesNotExist', self::EXTENSION_KEY);
    }

    #[Test]
    public function unknownSkeletonThrows(): void
    {
        $this->expectException(CrafterException::class);
        $this->expectExceptionCode(1757426403);

        $this->pathResolver->resolveSkeleton('Php/DoesNotExist', self::EXTENSION_KEY);
    }
}
