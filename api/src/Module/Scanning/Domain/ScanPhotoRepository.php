<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Scanning\Domain;

use Symfony\Component\Uid\Uuid;

interface ScanPhotoRepository
{
    /**
     * Keeps the photo unless its pairing already holds that many still waiting, sent since `$since`; whether it was
     * kept. Counting and keeping are one step for the pairing: two photos sent at once cannot both count under it.
     */
    public function saveWhileFewerThan(ScanPhoto $photo, int $held, \DateTimeImmutable $since): bool;

    public function ofPairing(Uuid $pairingId, Uuid $photoId): ?ScanPhoto;

    public function remove(ScanPhoto $photo): void;

    /** Clears every photo the phone sent before that moment; how many were cleared. */
    public function removeSentBefore(\DateTimeImmutable $moment): int;
}
