<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

use App\Identity\Domain\Email;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A mailed offer to open an account and a company, made to an address that asked for one. It is single use and it
 * expires; an unknown link, an expired one and one already used are the same non-answer to whoever presents it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'signup')]
#[ORM\UniqueConstraint(name: 'uniq_signup_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_signup_email', columns: ['email'])]
class Signup
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'email', length: 254)]
    private Email $email;

    /** The language the person asked in: their account's, and their company's when its country documents in it. */
    #[ORM\Column(length: 5)]
    private string $locale;

    /** SHA-256 of the raw token, hex; the raw value is in the mail and nowhere else. */
    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(Email $email, string $locale, SignupToken $token, \DateTimeImmutable $now, \DateInterval $validFor)
    {
        $this->id = Uuid::v7();
        $this->email = $email;
        $this->locale = $locale;
        $this->tokenHash = $token->hash();
        $this->createdAt = $now;
        $this->expiresAt = $now->add($validFor);
    }

    /** Usable up to and including the instant it expires. */
    public function isUsableAt(\DateTimeImmutable $now): bool
    {
        return null === $this->completedAt && $now <= $this->expiresAt;
    }

    /** @throws \DomainException when it was already used or has expired */
    public function complete(\DateTimeImmutable $now): void
    {
        if (!$this->isUsableAt($now)) {
            throw new \DomainException('That signup link can no longer be used.');
        }
        $this->completedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
