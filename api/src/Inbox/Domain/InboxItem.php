<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Domain;

use App\Identity\Domain\User;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One notification as its recipient keeps it: a row of the notification centre. Publishing writes it before the
 * real-time push, so the push may fail and the centre still shows it (docs/SPEC.md § 7, 2026-09-13). The type is
 * a code, never a sentence: the browser renders it through its translations with the payload as parameters.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inbox_item')]
#[ORM\Index(name: 'idx_inbox_item_recipient_created', columns: ['recipient_id', 'created_at'])]
class InboxItem
{
    private const string TYPE_PATTERN = '/^[a-z][a-z_]*(\.[a-z][a-z_]*)+$/';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $recipient;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Company $company;

    #[ORM\Column(length: 80)]
    private string $type;

    /** @var array<string, scalar|null> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    /** @param array<string, scalar|null> $payload */
    public function __construct(User $recipient, ?Company $company, string $type, array $payload, \DateTimeImmutable $now)
    {
        if (\strlen($type) > 80 || 1 !== preg_match(self::TYPE_PATTERN, $type)) {
            throw new \InvalidArgumentException(\sprintf('A notification type is a dotted lower-case code such as "membership.added", "%s" given.', $type));
        }

        $this->id = Uuid::v7();
        $this->recipient = $recipient;
        $this->company = $company;
        $this->type = $type;
        $this->payload = $payload;
        $this->createdAt = $now;
    }

    /** Idempotent: reading an item twice keeps the moment it was first read. */
    public function markRead(\DateTimeImmutable $at): void
    {
        $this->readAt ??= $at;
    }

    public function isRead(): bool
    {
        return null !== $this->readAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /** @return array<string, scalar|null> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }
}
