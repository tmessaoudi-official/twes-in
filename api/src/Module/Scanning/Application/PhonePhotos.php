<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Scanning\Application;

use App\Files\Application\Pictures;
use App\Module\Scanning\Domain\ScanPairingRefused;
use App\Module\Scanning\Domain\ScanPairingRepository;
use App\Module\Scanning\Domain\ScanPhoto;
use App\Module\Scanning\Domain\ScanPhotoRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * A photo the paired phone takes for the tab that lent it. The phone hands the picture over with its key; it waits
 * here while the tab is told, and the tab takes it once, under its own session, to send it on wherever the person is,
 * such as a product's photos, exactly as a picture picked from the computer would be. The phone learns nothing of what
 * became of it but what the tab echoes. Only a picture is held, read from its bytes, no larger than a product's photo
 * may be, and only a few at a time for one pairing.
 */
final readonly class PhonePhotos
{
    /** What one pairing holds at once: a person takes a photo, turns to the computer, and the tab takes it. */
    public const int HELD_MAX = 3;

    public function __construct(
        private PhonePairings $pairings,
        private ScanPairingRepository $pairingRecords,
        private ScanPhotoRepository $photos,
        private Pictures $pictures,
        private ClockInterface $clock,
        #[Autowire(param: 'app.products.photo_max_bytes')]
        private int $maxBytes,
    ) {
    }

    /**
     * The key first, as for a scan: a caller without it learns nothing of what a photo may be.
     *
     * @throws ScanPairingRefused
     * @throws \InvalidArgumentException when it is not a picture, too large, or one too many
     */
    public function hold(Uuid $id, string $key, string $scanId, string $bytes): Uuid
    {
        $pairing = $this->pairings->phone($id, $key);
        if (!Uuid::isValid($scanId)) {
            throw new \InvalidArgumentException('scan: a UUID.');
        }
        if ('' === $bytes || \strlen($bytes) > $this->maxBytes) {
            throw new \InvalidArgumentException(\sprintf('file: a picture of at most %d bytes.', $this->maxBytes));
        }
        $picture = $this->pictures->inspect($bytes) ?? throw new \InvalidArgumentException('file: a JPEG, PNG or WebP picture.');
        if ($this->photos->countOfPairing($pairing->getId()) >= self::HELD_MAX) {
            throw new \InvalidArgumentException(\sprintf('At most %d photos wait for the computer at once.', self::HELD_MAX));
        }
        $photo = new ScanPhoto($pairing, $picture->mime, $bytes, $this->clock->now());
        $this->photos->save($photo);
        $this->pairings->tellTheTab($pairing, ['event' => 'photo', 'scan' => $scanId, 'photo' => $photo->getId()->toRfc4122()]);

        return $photo->getId();
    }

    /**
     * The photo, once, for the person who lent the phone; its wait ends as it is taken.
     *
     * @return array{string, string} its type and its bytes
     *
     * @throws ScanPairingRefused when there is no such photo for that person, or it waited too long
     */
    public function take(Uuid $userId, Uuid $pairingId, Uuid $photoId): array
    {
        $pairing = $this->pairingRecords->ofUser($userId, $pairingId) ?? throw new ScanPairingRefused('unknown');
        $photo = $this->photos->ofPairing($pairing->getId(), $photoId);
        if (null === $photo || !$photo->waits($this->clock->now())) {
            throw new ScanPairingRefused('unknown');
        }
        $taken = [$photo->getMime(), $photo->getContents()];
        $this->photos->remove($photo);

        return $taken;
    }

    /** Every photo nobody took in time; how many. */
    public function clearUntaken(): int
    {
        return $this->photos->removeSentBefore(ScanPhoto::waitedSince($this->clock->now()));
    }
}
