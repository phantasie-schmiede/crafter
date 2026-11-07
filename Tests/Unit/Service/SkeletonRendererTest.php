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
use PHPUnit\Framework\ExpectationFailedException;
use PSBits\Crafter\Event\AfterSkeletonRenderEvent;
use PSBits\Crafter\Event\BeforeSkeletonRenderEvent;
use PSBits\Crafter\Exception\CrafterException;
use PSBits\Crafter\Service\SkeletonRenderer;
use PSBits\Crafter\Tests\Fixtures\CallbackEventDispatcher;
use PSBits\Crafter\Tests\Unit\AbstractCrafterTestCase;
use RuntimeException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function dirname;

/**
 * The stubs used here are written into the project wide skeleton root path, which is the very mechanism a project
 * uses to override a shipped stub.
 */
final class SkeletonRendererTest extends AbstractCrafterTestCase
{
    /**
     * @throws CrafterException
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    #[Test]
    public function afterEventCanRewriteTheRenderedContent(): void
    {
        $this->writeSkeleton('Test/Simple', 'value: {{ name }}');

        $dispatcher = new CallbackEventDispatcher();
        $dispatcher->addListener(static function(object $event): void {
            if ($event instanceof AfterSkeletonRenderEvent) {
                $event->setContent($event->getContent() . ' (patched)');
            }
        });

        self::assertSame(
            'value: Product (patched)',
            $this->createRenderer($dispatcher)->render('Test/Simple', ['name' => 'Product'])
        );
    }

    /**
     * @throws CrafterException
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    #[Test]
    public function beforeEventCanRedirectToAnotherSkeleton(): void
    {
        $this->writeSkeleton('Test/Original', 'original');
        $this->writeSkeleton('Test/Replacement', 'replacement');

        $dispatcher = new CallbackEventDispatcher();
        $dispatcher->addListener(static function(object $event): void {
            if ($event instanceof BeforeSkeletonRenderEvent && 'Test/Original' === $event->getIdentifier()) {
                $event->setIdentifier('Test/Replacement');
            }
        });

        self::assertSame('replacement', $this->createRenderer($dispatcher)->render('Test/Original'));
    }

    /**
     * @throws CrafterException
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    #[Test]
    public function blockPlaceholderIndentsEveryLineOfTheValue(): void
    {
        $this->writeSkeleton('Test/Block', "class Foo\n{\n    {{ body }}\n}\n");

        self::assertSame(
            "class Foo\n{\n    first();\n    second();\n}\n",
            $this->skeletonRenderer->render('Test/Block', ['body' => "first();\nsecond();"])
        );
    }

    /**
     * @throws CrafterException
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    #[Test]
    public function blockPlaceholderKeepsEmptyLinesUnindented(): void
    {
        $this->writeSkeleton('Test/Block', "class Foo\n{\n    {{ body }}\n}\n");

        self::assertSame(
            "class Foo\n{\n    first();\n\n    second();\n}\n",
            $this->skeletonRenderer->render('Test/Block', ['body' => "first();\n\nsecond();"])
        );
    }

    /**
     * @throws CrafterException
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    #[Test]
    public function emptyBlockPlaceholderRemovesTheWholeLine(): void
    {
        $this->writeSkeleton('Test/Block', "class Foo\n{\n    {{ body }}\n}\n");

        self::assertSame(
            "class Foo\n{\n}\n",
            $this->skeletonRenderer->render('Test/Block', ['body' => ''])
        );
    }

    /**
     * @throws CrafterException
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    #[Test]
    public function inlinePlaceholderIsReplacedInPlace(): void
    {
        $this->writeSkeleton('Test/Inline', 'class {{ name }}Repository extends {{ parent }}');

        self::assertSame(
            'class ProductRepository extends Repository',
            $this->skeletonRenderer->render('Test/Inline', ['name' => 'Product', 'parent' => 'Repository'])
        );
    }

    /**
     * @throws CrafterException
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    #[Test]
    public function projectSkeletonOverridesTheShippedOne(): void
    {
        $this->writeSkeleton('Php/FileHeader', "/*\n * Custom copyright of {{ packageTitle }}.\n */");

        self::assertStringContainsString(
            'Custom copyright of PSBits Demo Shop.',
            $this->skeletonRenderer->getGlobalVariables(self::EXTENSION_KEY)['fileHeader']
        );
    }

    /**
     * @throws CrafterException
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    #[Test]
    public function unknownPlaceholderIsResolvedFromTheConfiguration(): void
    {
        $this->writeSkeleton('Test/Configuration', '{{ paths.model }}');

        self::assertSame(
            'Classes/Domain/Model/{name}.php',
            $this->skeletonRenderer->render('Test/Configuration')
        );
    }

    /**
     * @throws CrafterException
     * @throws RuntimeException
     */
    #[Test]
    public function unresolvablePlaceholderThrows(): void
    {
        $this->writeSkeleton('Test/Broken', '{{ doesNotExist }}');

        $this->expectException(CrafterException::class);
        $this->expectExceptionCode(1757426407);

        $this->skeletonRenderer->render('Test/Broken');
    }

    private function createRenderer(CallbackEventDispatcher $dispatcher): SkeletonRenderer
    {
        return new SkeletonRenderer($this->configurationService, $dispatcher, $this->pathResolver);
    }

    /**
     * @throws RuntimeException
     */
    private function writeSkeleton(string $identifier, string $content): void
    {
        $path = $this->projectPath . '/config/Crafter/Skeletons/' . $identifier . '.stub';
        GeneralUtility::mkdir_deep(dirname($path));
        GeneralUtility::writeFile($path, $content, true);
    }
}
