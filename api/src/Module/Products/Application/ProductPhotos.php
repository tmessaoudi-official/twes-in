<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Files\Application\Files;
use App\Files\Application\Pictures;
use App\Files\Domain\StoredFile;
use App\Module\Products\Domain\PhotoSize;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductPhoto;
use App\Module\Products\Domain\ProductPhotoRepository;
use App\Module\Products\Domain\ProductRepository;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * A product's photos. A photo is a picture read from its bytes, never from its name: a JPEG, a PNG or a WebP. Its
 * size is checked from its header before anything decodes it, and the server cuts the small and large copies once,
 * when it arrives; the original is kept as it was sent. The first photo of a product is its main one until another is
 * marked; removing the main one makes the first of the rest main. Every limit is a parameter (services.yaml). Each
 * stored picture is a file of the company, so photos count in what the company keeps. Audited on the product, which
 * is also the live signal its screens reload on, with no value but the field's name.
 */
final readonly class ProductPhotos
{
    public const string ADDED = 'product.photo_added';
    public const string REMOVED = 'product.photo_removed';
    public const string RESTORED = 'product.photo_restored';
    public const string REORDERED = 'product.photos_reordered';
    public const string MAIN_CHANGED = 'product.main_photo_changed';

    public function __construct(
        private ProductRepository $products,
        private ProductPhotoRepository $photos,
        private Files $files,
        private Pictures $pictures,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
        #[Autowire(param: 'app.products.photos_per_product')]
        private int $perProduct,
        #[Autowire(param: 'app.products.photo_max_bytes')]
        private int $maxBytes,
        #[Autowire(param: 'app.products.photo_max_megapixels')]
        private int $maxMegapixels,
        #[Autowire(param: 'app.products.photo_small_side')]
        private int $smallSide,
        #[Autowire(param: 'app.products.photo_large_side')]
        private int $largeSide,
    ) {
    }

    /** How many photos a product may have: a screen says so before a photo is sent. */
    public function perProduct(): int
    {
        return $this->perProduct;
    }

    /** How large one photo may be, in bytes. */
    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * @return list<ProductPhoto> the gallery, in its order
     *
     * @throws ProductNotFound
     */
    public function of(Company $company, Uuid $productId): array
    {
        return $this->photos->ofProduct($this->product($company, $productId)->getId(), $company->getId());
    }

    /**
     * @param list<Uuid> $productIds
     *
     * @return array<string, string> the main photo's id by product id, only for the products that have one
     */
    public function mainPhotoIdsOf(Company $company, array $productIds): array
    {
        return $this->photos->mainPhotoIdsOf($company->getId(), $productIds);
    }

    /**
     * @throws ProductNotFound
     * @throws ProductPhotoRefused
     */
    public function add(Company $company, Uuid $productId, string $originalName, string $bytes, ?Uuid $actorUserId): ProductPhoto
    {
        $this->product($company, $productId);
        if ('' === $bytes) {
            throw new ProductPhotoRefused('The file is empty.', ProductPhotoRefused::EMPTY);
        }
        if (\strlen($bytes) > $this->maxBytes) {
            throw new ProductPhotoRefused(\sprintf('A photo is at most %d KB.', intdiv($this->maxBytes, 1024)), ProductPhotoRefused::TOO_LARGE, ['maxBytes' => $this->maxBytes]);
        }
        $picture = $this->pictures->inspect($bytes) ?? throw new ProductPhotoRefused('A photo is a JPEG, PNG or WebP picture.', ProductPhotoRefused::NOT_A_PICTURE);
        if ($picture->pixels() > $this->maxMegapixels * 1_000_000) {
            throw new ProductPhotoRefused(\sprintf('A photo is at most %d megapixels.', $this->maxMegapixels), ProductPhotoRefused::TOO_MANY_PIXELS, ['maxMegapixels' => $this->maxMegapixels]);
        }
        // Cut before the transaction: decoding is the slow part, and it holds no lock.
        $small = $this->pictures->fitted($bytes, $this->smallSide);
        $large = $this->pictures->fitted($bytes, $this->largeSide);

        return $this->transactions->run(function () use ($company, $productId, $originalName, $bytes, $picture, $small, $large, $actorUserId): ProductPhoto {
            $product = $this->product($company, $productId);
            $this->photos->lockProduct($product->getId());
            $gallery = $this->photos->ofProduct($product->getId(), $company->getId());
            if (\count($gallery) >= $this->perProduct) {
                throw new ProductPhotoRefused(\sprintf('A product has at most %d photos.', $this->perProduct), ProductPhotoRefused::TOO_MANY_PHOTOS, ['max' => $this->perProduct]);
            }
            $name = StoredFile::nameFrom($originalName, 'photo');
            $stem = pathinfo($name, \PATHINFO_FILENAME);
            $photo = new ProductPhoto(
                $product,
                $this->files->store($company, $name, $picture->mime, $bytes, $actorUserId),
                $this->files->store($company, mb_substr($stem, 0, 240).'.small.webp', 'image/webp', $small, $actorUserId),
                $this->files->store($company, mb_substr($stem, 0, 240).'.large.webp', 'image/webp', $large, $actorUserId),
                $picture->width,
                $picture->height,
                [] === $gallery ? 0 : max(array_map(static fn (ProductPhoto $one): int => $one->getPosition(), $gallery)) + 1,
                null === self::mainOf($gallery),
                $this->clock->now(),
            );
            $this->photos->save($photo);
            $this->record($company, $product->getId(), self::ADDED, $actorUserId);

            return $photo;
        });
    }

    /**
     * @throws ProductNotFound
     * @throws ProductPhotoNotFound
     */
    public function markMain(Company $company, Uuid $productId, Uuid $photoId, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $productId, $photoId, $actorUserId): void {
            $gallery = $this->lockedGallery($company, $productId);
            $photo = self::find($gallery, $photoId);
            if ($photo->isMain()) {
                return;
            }
            $this->handMainTo($photo, self::mainOf($gallery));
            $this->record($company, $productId, self::MAIN_CHANGED, $actorUserId);
        });
    }

    /**
     * @param list<Uuid> $photoIds every photo of the gallery, in the order wanted
     *
     * @throws ProductNotFound
     * @throws ProductPhotosChanged
     */
    public function order(Company $company, Uuid $productId, array $photoIds, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $productId, $photoIds, $actorUserId): void {
            $byId = [];
            foreach ($this->lockedGallery($company, $productId) as $photo) {
                $byId[$photo->getId()->toRfc4122()] = $photo;
            }
            $wanted = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $photoIds);
            if (\count($wanted) !== \count($byId) || \count(array_unique($wanted)) !== \count($wanted) || [] !== array_diff($wanted, array_keys($byId))) {
                throw new ProductPhotosChanged();
            }
            $moved = false;
            foreach ($wanted as $position => $id) {
                if ($byId[$id]->getPosition() !== $position) {
                    $byId[$id]->moveTo($position);
                    $this->photos->save($byId[$id]);
                    $moved = true;
                }
            }
            if ($moved) {
                $this->record($company, $productId, self::REORDERED, $actorUserId);
            }
        });
    }

    /**
     * Takes the photo out of the gallery and keeps it, so the removal can be undone.
     *
     * @throws ProductNotFound
     * @throws ProductPhotoNotFound
     */
    public function remove(Company $company, Uuid $productId, Uuid $photoId, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $productId, $photoId, $actorUserId): void {
            $gallery = $this->lockedGallery($company, $productId);
            $photo = self::find($gallery, $photoId);
            // Saved first, out of the gallery, so the next main photo never meets it in the unique index.
            $photo->remove($this->clock->now());
            $this->photos->save($photo);
            $next = $photo->isMain() ? (array_values(array_filter($gallery, static fn (ProductPhoto $one): bool => $one !== $photo))[0] ?? null) : null;
            if (null !== $next) {
                $next->markMain(true);
                $this->photos->save($next);
            }
            $this->record($company, $productId, self::REMOVED, $actorUserId);
        });
    }

    /**
     * Puts a removed photo back where it was; the main one it was is main again.
     *
     * @throws ProductNotFound
     * @throws ProductPhotoNotFound
     * @throws ProductPhotoRefused
     */
    public function restore(Company $company, Uuid $productId, Uuid $photoId, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $productId, $photoId, $actorUserId): void {
            $gallery = $this->lockedGallery($company, $productId);
            $photo = $this->photos->ofIdInProduct($photoId, $productId, $company->getId()) ?? throw new ProductPhotoNotFound();
            if (!$photo->isRemoved()) {
                return;
            }
            if (\count($gallery) >= $this->perProduct) {
                throw new ProductPhotoRefused(\sprintf('A product has at most %d photos.', $this->perProduct), ProductPhotoRefused::TOO_MANY_PHOTOS, ['max' => $this->perProduct]);
            }
            $main = self::mainOf($gallery);
            if ($photo->isMain() && null !== $main) {
                $main->markMain(false);
                $this->photos->save($main);
            }
            if (null === $main) {
                $photo->markMain(true);
            }
            $photo->restore();
            $this->photos->save($photo);
            $this->record($company, $productId, self::RESTORED, $actorUserId);
        });
    }

    /**
     * @return array{string, string} the type and the bytes of the picture asked for
     *
     * @throws ProductNotFound
     * @throws ProductPhotoNotFound
     */
    public function contents(Company $company, Uuid $productId, Uuid $photoId, PhotoSize $size): array
    {
        $photo = self::find($this->of($company, $productId), $photoId);
        $file = $photo->file($size);

        return [$file->getMime(), $this->files->contents($file)];
    }

    /**
     * What the customer screen may show: the main photo's copies, never the original as sent nor another photo of the
     * gallery, which are the company's own working material.
     *
     * @return array{string, string} the type and the bytes of the copy asked for
     *
     * @throws ProductNotFound
     * @throws ProductPhotoNotFound
     */
    public function mainCopy(Company $company, Uuid $productId, Uuid $photoId, PhotoSize $size): array
    {
        $photo = self::find($this->of($company, $productId), $photoId);
        if (PhotoSize::Original === $size || !$photo->isMain()) {
            throw new ProductPhotoNotFound();
        }
        $file = $photo->file($size);

        return [$file->getMime(), $this->files->contents($file)];
    }

    /** @throws ProductNotFound */
    private function product(Company $company, Uuid $productId): Product
    {
        return $this->products->ofIdInCompany($productId, $company->getId()) ?? throw new ProductNotFound();
    }

    /**
     * @return list<ProductPhoto>
     *
     * @throws ProductNotFound
     */
    private function lockedGallery(Company $company, Uuid $productId): array
    {
        $product = $this->product($company, $productId);
        $this->photos->lockProduct($product->getId());

        return $this->photos->ofProduct($product->getId(), $company->getId());
    }

    /** The one main photo steps down and is saved first, so the partial unique index never sees two at once. */
    private function handMainTo(ProductPhoto $photo, ?ProductPhoto $main): void
    {
        if (null !== $main) {
            $main->markMain(false);
            $this->photos->save($main);
        }
        $photo->markMain(true);
        $this->photos->save($photo);
    }

    private function record(Company $company, Uuid $productId, string $action, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(ManageProducts::ENTITY_TYPE, $productId, $action, $actorUserId, ['fields' => ['photos']], $company->getId()));
    }

    /**
     * @param list<ProductPhoto> $gallery
     *
     * @throws ProductPhotoNotFound
     */
    private static function find(array $gallery, Uuid $photoId): ProductPhoto
    {
        foreach ($gallery as $photo) {
            if ($photo->getId()->equals($photoId)) {
                return $photo;
            }
        }

        throw new ProductPhotoNotFound();
    }

    /** @param list<ProductPhoto> $gallery */
    private static function mainOf(array $gallery): ?ProductPhoto
    {
        foreach ($gallery as $photo) {
            if ($photo->isMain()) {
                return $photo;
            }
        }

        return null;
    }
}
