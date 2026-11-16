<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application\Photo;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Files\Application\Files;
use App\Module\Products\Application\ManageProducts;
use App\Module\Products\Domain\Repository\ProductPhotoRepository;
use App\Module\Products\Domain\ValueObject\PhotoSize;
use App\Shared\Application\Transactions;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Lets a removed product photo go once it has been out of the gallery for the time the operator keeps one
 * (app.products.removed_photos_kept_for): its row goes, then its three files, which stop counting in what the company keeps, and it
 * can no longer be put back. With no time set, the shipped default, a removed photo is kept as long as its product is.
 * Each photo in a transaction of its own; running it again lets nothing go twice.
 */
final readonly class LetRemovedPhotosGo implements DeclaresAuditActions
{
    public const string LET_GO = 'product.photo_let_go';
    /** Photos read per pass: the next pass, an hour later, takes the rest. */
    private const int BATCH = 200;

    public function __construct(
        private ProductPhotoRepository $photos,
        private Files $files,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
        #[Autowire(param: 'app.products.removed_photos_kept_for')]
        private ?string $keptFor,
    ) {
    }

    /** @return int how many photos it let go */
    public function handle(): int
    {
        if (null === $this->keptFor) {
            return 0;
        }
        $before = $this->clock->now()->sub(new \DateInterval($this->keptFor));
        $letGo = 0;
        foreach ($this->photos->removedBefore($before, self::BATCH) as ['company' => $company, 'product' => $product, 'id' => $id]) {
            $keys = $this->transactions->run(function () use ($company, $product, $id, $before): ?array {
                // The product's row first, as a restore holds it: a restore waiting on it then finds the photo gone.
                $this->photos->lockProduct($product);
                $photo = $this->photos->lockedRemoved($company, $id);
                $removedAt = $photo?->getRemovedAt();
                // Put back, or removed again later, since the list was read.
                if (null === $photo || null === $removedAt || $removedAt >= $before) {
                    return null;
                }
                $files = array_map(static fn (PhotoSize $size): Uuid => $photo->file($size)->getId(), PhotoSize::cases());
                $productId = $photo->getProduct()->getId();
                $this->photos->delete($photo);
                $this->audit->record(new AuditEntry(ManageProducts::ENTITY_TYPE, $productId, self::LET_GO, null, ['fields' => ['photos']], $company));

                // The photo's own three files: each upload stores its own, and no other row names them.
                return $this->files->forget($company, $files);
            });
            if (null !== $keys) {
                // The bytes once their records are gone for good: bytes without a record are only unused.
                $this->files->deleteContents($keys);
                ++$letGo;
            }
        }

        return $letGo;
    }

    public function auditActions(): array
    {
        return [
            self::LET_GO => new Recorded(ManageProducts::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
