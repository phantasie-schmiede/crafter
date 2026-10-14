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
use PSBits\Crafter\Data\PropertyDefinition;
use PSBits\Crafter\Service\FieldTypeRegistry;
use PSBits\Crafter\Service\ModelPropertyReader;
use PSBits\Crafter\Tests\Unit\AbstractCrafterTestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function dirname;

/**
 * Class ModelPropertyReaderTest
 *
 * Feeds the reader with the real output of the model generator and with hand written sources, so the attribute
 * patterns of every configured field type are verified.
 *
 * @package PSBits\Crafter\Tests\Unit\Service
 */
final class ModelPropertyReaderTest extends AbstractCrafterTestCase
{
    private ModelPropertyReader $modelPropertyReader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modelPropertyReader = new ModelPropertyReader(new FieldTypeRegistry($this->configurationService));
    }

    #[Test]
    public function readsThePropertiesOfAGeneratedModel(): void
    {
        $byName = $this->readByProperty(dirname(__DIR__, 2) . '/Fixtures/Expected/Product.php');

        self::assertSame(
            [
                'active'      => 'boolean',
                'category'    => 'relationSingle',
                'description' => 'text',
                'price'       => 'decimal',
                'releaseDate' => 'date',
                'tags'        => 'relationMultiple',
                'title'       => 'string',
            ],
            array_map(static fn(PropertyDefinition $property): string => $property->type, $byName)
        );
        self::assertTrue($byName['title']->required);
        self::assertFalse($byName['price']->required);
    }

    #[Test]
    public function returnsAnEmptyArrayForAMissingFile(): void
    {
        self::assertSame([], $this->modelPropertyReader->read('/does/not/exist/Model.php'));
    }

    #[Test]
    public function returnsAnEmptyArrayWithoutProtectedProperties(): void
    {
        $path = $this->writeModelSource(
            <<<'PHP'
                <?php

                class NotAModel
                {
                    private string $hidden = 'ignored';

                    public function getName(): string
                    {
                        return $this->hidden;
                    }
                }
                PHP
        );

        self::assertSame([], $this->modelPropertyReader->read($path));
    }

    #[Test]
    public function resolvesEveryConfiguredAttributePattern(): void
    {
        $path = $this->writeModelSource(
            <<<'PHP'
                <?php

                class Source
                {
                    #[Check]
                    protected bool $flag = false;

                    #[Color]
                    protected string $accent = '';

                    #[Datetime]
                    protected ?\DateTime $updatedAt = null;

                    #[Input(eval: 'trim,email')]
                    protected string $contactEmail = '';

                    #[Enum(enumClass: ProductStatus::class)]
                    protected ?ProductStatus $status = null;

                    #[File]
                    protected ObjectStorage $attachment;

                    #[Number]
                    protected int $stock = 0;

                    #[Link]
                    protected string $website = '';

                    #[Text(enableRichText: true)]
                    protected string $body = '';

                    #[Slug]
                    protected string $slug = '';
                }
                PHP
        );
        $byName = $this->readByProperty($path);

        self::assertSame(
            [
                'flag'         => 'boolean',
                'accent'       => 'color',
                'updatedAt'    => 'dateTime',
                'contactEmail' => 'email',
                'status'       => 'enum',
                'attachment'   => 'file',
                'stock'        => 'integer',
                'website'      => 'link',
                'body'         => 'richText',
                'slug'         => 'slug',
            ],
            array_map(static fn(PropertyDefinition $property): string => $property->type, $byName)
        );
    }

    #[Test]
    public function requiresEveryAttributeOfAType(): void
    {
        $path = $this->writeModelSource(
            <<<'PHP'
                <?php

                class Source
                {
                    #[Inline(foreignField: 'category', linkedModel: Category::class)]
                    protected ObjectStorage $items;

                    protected string $label = '';
                }
                PHP
        );
        $byName = $this->readByProperty($path);

        // The Cascade attribute is missing, so the inline type does not match. Both properties fall back to string.
        self::assertSame('string', $byName['items']->type);
        self::assertSame('string', $byName['label']->type);
    }

    #[Test]
    public function fallsBackToStringForAnUnknownAttribute(): void
    {
        $path = $this->writeModelSource(
            <<<'PHP'
                <?php

                class Source
                {
                    #[SomethingWeird]
                    protected string $mystery = '';
                }
                PHP
        );
        $byName = $this->readByProperty($path);

        self::assertSame('string', $byName['mystery']->type);
        self::assertFalse($byName['mystery']->required);
    }

    /**
     * @return array<string, PropertyDefinition>
     */
    private function readByProperty(string $filePath): array
    {
        $byName = [];

        foreach ($this->modelPropertyReader->read($filePath) as $property) {
            $byName[$property->name] = $property;
        }

        return $byName;
    }

    private function writeModelSource(string $source): string
    {
        $path = $this->getExtensionFilePath('Classes/Domain/Model/' . bin2hex(random_bytes(4)) . '.php');
        GeneralUtility::mkdir_deep(dirname($path));
        GeneralUtility::writeFile($path, $source, true);

        return $path;
    }
}
