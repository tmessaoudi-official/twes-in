<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A passkey registered as a second factor (G1c), beside or instead of an authenticator app.
 *
 * The WebAuthn credential record is kept whole, as the JSON the adapter produced: its public key, signature counter and
 * backup flags mean something only to the library that verifies the next assertion, so the domain stores it unread.
 */
#[ORM\Entity]
#[ORM\Table(name: 'passkey')]
#[ORM\UniqueConstraint(name: 'uniq_passkey_credential', columns: ['credential_id'])]
#[ORM\Index(name: 'idx_passkey_user', columns: ['user_id'])]
class Passkey
{
    public const int NAME_MAX = 80;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Base64url without padding, the form browsers report it in. */
    #[ORM\Column(length: 255)]
    private string $credentialId;

    #[ORM\Column(type: Types::TEXT)]
    private string $record;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(User $user, string $credentialId, string $record, string $name, ?\DateTimeImmutable $now = null)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->credentialId = $credentialId;
        $this->record = $record;
        $name = trim($name);
        // A name only helps the person tell their devices apart, so an empty one is given a neutral default.
        $this->name = '' === $name ? 'Passkey' : mb_substr($name, 0, self::NAME_MAX);
        $this->createdAt = $now ?? new \DateTimeImmutable();
    }

    /** A verified sign-in: the record carries the new signature counter, which a cloned key could not advance past. */
    public function recordUse(string $record, \DateTimeImmutable $now): void
    {
        $this->record = $record;
        $this->lastUsedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCredentialId(): string
    {
        return $this->credentialId;
    }

    public function getRecord(): string
    {
        return $this->record;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }
}
