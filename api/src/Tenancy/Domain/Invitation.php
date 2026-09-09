<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An offer to join a company, made to an address that has no account yet. It is single use and it expires;
 * an unknown token, an expired one and one already used are the same non-answer to whoever presents it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invitation')]
#[ORM\UniqueConstraint(name: 'uniq_invitation_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_invitation_company', columns: ['company_id'])]
class Invitation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(type: 'email', length: 254)]
    private Email $email;

    #[ORM\Column(length: 64)]
    private string $roleName;

    /** SHA-256 of the raw token, hex; the raw value is in the mail and nowhere else. */
    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'invited_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $invitedBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    public function __construct(
        Company $company,
        Email $email,
        string $roleName,
        InvitationToken $token,
        \DateTimeImmutable $now,
        \DateInterval $validFor,
        ?User $invitedBy,
    ) {
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->email = $email;
        $this->roleName = $roleName;
        $this->tokenHash = $token->hash();
        $this->invitedBy = $invitedBy;
        $this->createdAt = $now;
        $this->expiresAt = $now->add($validFor);
    }

    /** Usable up to and including the instant it expires. */
    public function isUsableAt(\DateTimeImmutable $now): bool
    {
        return null === $this->acceptedAt && $now <= $this->expiresAt;
    }

    /** @throws \DomainException when it was already used or has expired */
    public function accept(\DateTimeImmutable $now): void
    {
        if (!$this->isUsableAt($now)) {
            throw new \DomainException('That invitation can no longer be used.');
        }
        $this->acceptedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function getRoleName(): string
    {
        return $this->roleName;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getInvitedBy(): ?User
    {
        return $this->invitedBy;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
