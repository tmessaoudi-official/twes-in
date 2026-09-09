<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One row per recorded action: who did what to which entity, from where, when. Authentication events are
 * rows here too (entity_type "user", actions "auth.*"), by ruling — one table, one reader.
 * Append-only: nothing on this class mutates a row after construction.
 */
#[ORM\Entity]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_log_entity', columns: ['entity_type', 'entity_id'])]
#[ORM\Index(name: 'idx_audit_log_at', columns: ['at'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    /** Null for platform-scope and pre-login events; every company-scoped action carries its company. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $companyId;

    #[ORM\Column(length: 64)]
    private string $entityType;

    /** Not a foreign key: the row outlives the entity it describes. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $entityId;

    #[ORM\Column(length: 64)]
    private string $action;

    /** Null when nobody is authenticated (a failed login names the attempted email in changes instead). */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $actorUserId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $changes;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $at;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip;

    /** @param array<string, mixed> $changes */
    public function __construct(string $entityType, ?Uuid $entityId, string $action, ?Uuid $actorUserId, array $changes, \DateTimeImmutable $at, ?string $ip, ?Uuid $companyId = null)
    {
        $this->id = Uuid::v7();
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->action = $action;
        $this->actorUserId = $actorUserId;
        $this->changes = $changes;
        $this->at = $at;
        $this->ip = $ip;
        $this->companyId = $companyId;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompanyId(): ?Uuid
    {
        return $this->companyId;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): ?Uuid
    {
        return $this->entityId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getActorUserId(): ?Uuid
    {
        return $this->actorUserId;
    }

    /** @return array<string, mixed> */
    public function getChanges(): array
    {
        return $this->changes;
    }

    public function getAt(): \DateTimeImmutable
    {
        return $this->at;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }
}
