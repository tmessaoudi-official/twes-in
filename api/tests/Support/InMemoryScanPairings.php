<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Scanning\Domain\ScanPairing;
use App\Scanning\Domain\ScanPairingRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryScanPairings implements ScanPairingRepository
{
    /** @var array<string, ScanPairing> */
    private array $pairings = [];

    public function save(ScanPairing ...$pairings): void
    {
        foreach ($pairings as $pairing) {
            $this->pairings[$pairing->getId()->toRfc4122()] = $pairing;
        }
    }

    public function get(Uuid $id): ?ScanPairing
    {
        return $this->pairings[$id->toRfc4122()] ?? null;
    }

    public function ofUser(Uuid $userId, Uuid $id): ?ScanPairing
    {
        $pairing = $this->get($id);

        return null !== $pairing && $pairing->getUser()->getId()->equals($userId) ? $pairing : null;
    }

    public function lockedByLinkHash(string $linkHash): ?ScanPairing
    {
        foreach ($this->pairings as $pairing) {
            if ($pairing->getLinkHash() === $linkHash) {
                return $pairing;
            }
        }

        return null;
    }

    public function unendedOf(Uuid $userId): array
    {
        return array_values(array_filter(
            $this->pairings,
            static fn (ScanPairing $pairing): bool => null === $pairing->getEndedAt() && $pairing->getUser()->getId()->equals($userId),
        ));
    }
}
