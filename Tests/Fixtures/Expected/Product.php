<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Demo Shop.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\DemoShop\Domain\Model;

use PSBits\Foundation\Attribute\TCA\Column;
use PSBits\Foundation\Attribute\TCA\ColumnType\Check;
use PSBits\Foundation\Attribute\TCA\ColumnType\Datetime;
use PSBits\Foundation\Attribute\TCA\ColumnType\Input;
use PSBits\Foundation\Attribute\TCA\ColumnType\Number;
use PSBits\Foundation\Attribute\TCA\ColumnType\Select;
use PSBits\Foundation\Attribute\TCA\ColumnType\Text;
use PSBits\Foundation\Attribute\TCA\Ctrl;
use PSBits\Foundation\Enum\DateType;
use PSBits\Foundation\Enum\NumberFormat;
use PSBits\Foundation\Enum\SelectRenderType;
use TYPO3\CMS\Extbase\Annotation\Validate;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * Class Product
 *
 * @package PSBits\DemoShop\Domain\Model
 */
#[Ctrl(label: 'title')]
class Product extends AbstractEntity
{
    #[Check]
    protected bool $active = false;

    #[Select(linkedModel: Category::class)]
    protected ?Category $category = null;

    #[Text]
    protected string $description = '';

    #[Number(format: NumberFormat::decimal)]
    protected float $price = 0.0;

    #[Datetime(format: DateType::date)]
    protected ?\DateTime $releaseDate = null;

    /** @var ObjectStorage<Tag> */
    #[Select(linkedModel: Tag::class, mm: 'tx_demoshop_domain_model_product_tags_mm', renderType: SelectRenderType::selectMultipleSideBySide)]
    protected ObjectStorage $tags;

    #[Column(required: true)]
    #[Input]
    #[Validate(['validator' => 'NotEmpty'])]
    protected string $title = '';

    public function __construct()
    {
        $this->initializeObject();
    }

    public function addTag(Tag $tag): self
    {
        $this->tags->attach($tag);

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getReleaseDate(): ?\DateTime
    {
        return $this->releaseDate;
    }

    /** @return ObjectStorage<Tag> */
    public function getTags(): ObjectStorage
    {
        return $this->tags;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function initializeObject(): void
    {
        $this->tags = new ObjectStorage();
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function removeTag(Tag $tag): self
    {
        $this->tags->detach($tag);

        return $this;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function setCategory(?Category $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function setPrice(float $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function setReleaseDate(?\DateTime $releaseDate): self
    {
        $this->releaseDate = $releaseDate;

        return $this;
    }

    /** @param ObjectStorage<Tag> $tags */
    public function setTags(ObjectStorage $tags): self
    {
        $this->tags = $tags;

        return $this;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }
}
