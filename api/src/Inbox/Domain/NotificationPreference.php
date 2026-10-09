<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Domain;

use App\Identity\Domain\User;
use App\Tenancy\Domain\Company;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * How one person wants one kind of notification told in one company: the bell, which a muted kind does not count, and
 * an e-mail. A kind nobody chose has no row and is told both ways; a personal kind has no company. The key holds its
 * NULL company as one value (`NULLS NOT DISTINCT`, written in the migration), so a personal choice is one row too.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_preference')]
#[ORM\UniqueConstraint(name: 'uniq_notification_preference', columns: ['user_id', 'company_id', 'type'])]
class NotificationPreference
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Company $company;

    #[ORM\Column(length: 80)]
    private string $type;

    #[ORM\Column]
    private bool $bell;

    #[ORM\Column]
    private bool $email;

    public function __construct(User $user, ?Company $company, string $type, bool $bell, bool $email)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->company = $company;
        $this->type = $type;
        $this->bell = $bell;
        $this->email = $email;
    }

    public function change(bool $bell, bool $email): void
    {
        $this->bell = $bell;
        $this->email = $email;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function rings(): bool
    {
        return $this->bell;
    }

    public function mails(): bool
    {
        return $this->email;
    }
}
