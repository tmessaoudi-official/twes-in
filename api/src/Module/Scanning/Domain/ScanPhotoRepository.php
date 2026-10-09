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
    public function save(ScanPhoto $photo): void;

    /** How many photos of that pairing wait to be taken. */
    public function countOfPairing(Uuid $pairingId): int;

    public function ofPairing(Uuid $pairingId, Uuid $photoId): ?ScanPhoto;

    public function remove(ScanPhoto $photo): void;

    /** Clears every photo the phone sent before that moment; how many were cleared. */
    public function removeSentBefore(\DateTimeImmutable $moment): int;
}
