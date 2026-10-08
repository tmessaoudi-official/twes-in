<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

use App\Files\Domain\StoredFile;
use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A photo of a product: the picture as it was sent, kept whole, and the two copies the server cut from it, small for a
 * line in a list and large for a page. A product's photos are ordered, and one of them is its main photo, the one
 * shown wherever the product is picked or seen; being main and coming first are two things, so marking a photo main
 * moves nothing. A removed photo is kept, out of the gallery, so the removal can be undone; it keeps whether it was
 * the main one, which a partial unique index allows because it only counts the photos in the gallery.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_photo')]
#[ORM\Index(name: 'idx_product_photo_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_product_photo_product', columns: ['product_id', 'position'])]
#[ORM\Index(name: 'idx_product_photo_original', columns: ['original_file_id'])]
#[ORM\Index(name: 'idx_product_photo_small', columns: ['small_file_id'])]
#[ORM\Index(name: 'idx_product_photo_large', columns: ['large_file_id'])]
#[ORM\UniqueConstraint(name: 'uniq_product_photo_main', columns: ['product_id'], options: ['where' => '(is_main AND (removed_at IS NULL))'])]
class ProductPhoto implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: StoredFile::class)]
    #[ORM\JoinColumn(name: 'original_file_id', nullable: false)]
    private StoredFile $original;

    #[ORM\ManyToOne(targetEntity: StoredFile::class)]
    #[ORM\JoinColumn(name: 'small_file_id', nullable: false)]
    private StoredFile $small;

    #[ORM\ManyToOne(targetEntity: StoredFile::class)]
    #[ORM\JoinColumn(name: 'large_file_id', nullable: false)]
    private StoredFile $large;

    /** The original's size, upright. */
    #[ORM\Column]
    private int $width;

    #[ORM\Column]
    private int $height;

    #[ORM\Column]
    private int $position;

    #[ORM\Column]
    private bool $isMain;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $removedAt = null;

    public function __construct(Product $product, StoredFile $original, StoredFile $small, StoredFile $large, int $width, int $height, int $position, bool $isMain, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $product->getCompany();
        $this->product = $product;
        $this->original = $original;
        $this->small = $small;
        $this->large = $large;
        $this->width = $width;
        $this->height = $height;
        $this->position = $position;
        $this->isMain = $isMain;
        $this->createdAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function file(PhotoSize $size): StoredFile
    {
        return match ($size) {
            PhotoSize::Small => $this->small,
            PhotoSize::Large => $this->large,
            PhotoSize::Original => $this->original,
        };
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function isMain(): bool
    {
        return $this->isMain;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isRemoved(): bool
    {
        return null !== $this->removedAt;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }

    public function markMain(bool $main): void
    {
        $this->isMain = $main;
    }

    public function remove(\DateTimeImmutable $now): void
    {
        $this->removedAt = $now;
    }

    public function restore(): void
    {
        $this->removedAt = null;
    }
}
