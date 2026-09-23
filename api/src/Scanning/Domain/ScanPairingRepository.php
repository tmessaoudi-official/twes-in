<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Scanning\Domain;

use Symfony\Component\Uid\Uuid;

interface ScanPairingRepository
{
    public function save(ScanPairing ...$pairings): void;

    /** By id alone, for the phone, which has no session: the key it presents is what authorises it. */
    public function get(Uuid $id): ?ScanPairing;

    /** The pairing only when that person opened it: nobody keeps alive or ends somebody else's phone. */
    public function ofUser(Uuid $userId, Uuid $id): ?ScanPairing;

    /** Locked until the unit of work that asked commits, so two phones opening one link cannot both claim it. */
    public function lockedByLinkHash(string $linkHash): ?ScanPairing;

    /** @return list<ScanPairing> every pairing of that person not yet ended */
    public function unendedOf(Uuid $userId): array;
}
