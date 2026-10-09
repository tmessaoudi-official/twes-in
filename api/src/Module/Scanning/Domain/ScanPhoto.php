<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Scanning\Domain;

use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A photo the paired phone took, waiting for the tab that lent it to take it. Its bytes wait here, briefly, rather than
 * among the company's files: a stored file is kept for ever and counts in what the company keeps, while this one is
 * either taken, and then sent on as any photo from the device, or cleared once nobody took it in time.
 */
#[ORM\Entity]
#[ORM\Table(name: 'scan_photo')]
#[ORM\Index(name: 'idx_scan_photo_pairing', columns: ['pairing_id'])]
#[ORM\Index(name: 'idx_scan_photo_created', columns: ['created_at'])]
class ScanPhoto implements CompanyOwned
{
    /** How long a photo waits to be taken: enough for a person to turn back to the computer. */
    public const int WAITS_SECONDS = 900;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ScanPairing::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ScanPairing $pairing;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 32)]
    private string $mime;

    /** @var string|resource the bytes as sent; the database hands them back as a stream */
    #[ORM\Column(type: Types::BLOB)]
    private mixed $contents;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(ScanPairing $pairing, string $mime, string $contents, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->pairing = $pairing;
        $this->company = $pairing->getCompany();
        $this->mime = $mime;
        $this->contents = $contents;
        $this->createdAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPairing(): ScanPairing
    {
        return $this->pairing;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getMime(): string
    {
        return $this->mime;
    }

    public function getContents(): string
    {
        if (\is_resource($this->contents)) {
            $this->contents = (string) stream_get_contents($this->contents, -1, 0);
        }

        return \is_string($this->contents) ? $this->contents : '';
    }

    /** Whether it still waits at that moment, or is past being taken. */
    public function waits(\DateTimeImmutable $now): bool
    {
        return $now < $this->createdAt->modify('+'.self::WAITS_SECONDS.' seconds');
    }

    public static function waitedSince(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify('-'.self::WAITS_SECONDS.' seconds');
    }
}
