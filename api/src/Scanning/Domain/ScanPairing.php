<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Scanning\Domain;

use App\Identity\Domain\User;
use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A phone lent to one computer tab as a scanner (docs/SPEC.md § 7, 2026-09-23 09:45, slice 4). The computer shows a
 * single-use link; the phone that opens it first claims it and receives a key, which is all it holds: no session,
 * no company data, only the right to hand codes to that tab. The pairing lives while the tab says it is there —
 * closing the tab, signing out or going silent for ALIVE_SECONDS ends it. Only hashes of the link and the key are kept.
 */
#[ORM\Entity]
#[ORM\Table(name: 'scan_pairing')]
#[ORM\Index(name: 'idx_scan_pairing_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_scan_pairing_link', columns: ['link_hash'])]
class ScanPairing implements CompanyOwned
{
    /** How long the link shown on the computer can be claimed. */
    public const int CLAIM_WITHIN_SECONDS = 300;

    /** How long the pairing outlives the computer tab's last word; the tab renews it well within this. */
    public const int ALIVE_SECONDS = 90;

    private const int TAB_MAX = 64;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** The computer tab the scans go to: the `X-Tab` header of the request that opened the pairing. */
    #[ORM\Column(length: 64)]
    private string $tab;

    #[ORM\Column(length: 64)]
    private string $linkHash;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $keyHash = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $aliveUntil;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    public function __construct(Company $company, User $user, string $tab, string $link, \DateTimeImmutable $now)
    {
        if ('' === $tab || \strlen($tab) > self::TAB_MAX) {
            throw new \InvalidArgumentException(\sprintf('A tab is named in 1 to %d characters.', self::TAB_MAX));
        }
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->user = $user;
        $this->tab = $tab;
        $this->linkHash = self::linkHash($link);
        $this->createdAt = $now;
        $this->aliveUntil = self::aliveFrom($now);
    }

    /** How a link is looked up: by its hash, so a database read never yields a link that still works. */
    public static function linkHash(string $link): string
    {
        return hash('sha256', $link);
    }

    /** The phone's first and only use of the link: from now on the key is what it presents. */
    public function claim(string $key, \DateTimeImmutable $now): void
    {
        if (!$this->isLive($now)) {
            throw new ScanPairingRefused('ended');
        }
        if (null !== $this->claimedAt) {
            throw new ScanPairingRefused('claimed');
        }
        if ($now > $this->createdAt->modify(\sprintf('+%d seconds', self::CLAIM_WITHIN_SECONDS))) {
            throw new ScanPairingRefused('expired');
        }
        $this->keyHash = hash('sha256', $key);
        $this->claimedAt = $now;
    }

    /** Refuses unless this is the claimed pairing's key and the computer tab is still there. */
    public function authorise(string $key, \DateTimeImmutable $now): void
    {
        if (!$this->isLive($now)) {
            throw new ScanPairingRefused('ended');
        }
        if (null === $this->keyHash || !hash_equals($this->keyHash, hash('sha256', $key))) {
            throw new ScanPairingRefused('key');
        }
    }

    /** The computer tab saying it is still there. Too late is too late: a lapsed pairing is not revived. */
    public function renew(\DateTimeImmutable $now): void
    {
        if (!$this->isLive($now)) {
            throw new ScanPairingRefused('ended');
        }
        $this->aliveUntil = self::aliveFrom($now);
    }

    /** Idempotent: the first end is the one kept. */
    public function end(\DateTimeImmutable $now): void
    {
        $this->endedAt ??= $now;
    }

    public function isLive(\DateTimeImmutable $now): bool
    {
        return null === $this->endedAt && $now <= $this->aliveUntil;
    }

    public function isClaimed(): bool
    {
        return null !== $this->claimedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTab(): string
    {
        return $this->tab;
    }

    public function getLinkHash(): string
    {
        return $this->linkHash;
    }

    public function getKeyHash(): ?string
    {
        return $this->keyHash;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    private static function aliveFrom(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(\sprintf('+%d seconds', self::ALIVE_SECONDS));
    }
}
