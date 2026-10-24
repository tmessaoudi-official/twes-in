<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Domain\Model;

use App\Tenancy\Domain\Model\Company;
use App\Texts\Domain\ValueObject\TextAddress;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One text worded otherwise than the release ships it: for the whole installation when it names no company, for one
 * company otherwise, which then wins there. It keeps the original it replaced, so a release that changes that original
 * is seen and the shop chooses. The key holds the installation's NULL company as one value (`NULLS NOT DISTINCT`,
 * written in the migration), so the installation has one row per text too. Who wrote it is the audit trail's.
 */
#[ORM\Entity]
#[ORM\Table(name: 'text_override')]
#[ORM\UniqueConstraint(name: 'uniq_text_override', columns: ['company_id', 'language', 'domain', 'text_key'])]
class TextOverride
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Company $company;

    #[ORM\Column(length: 16)]
    private string $language;

    #[ORM\Column(length: 64)]
    private string $domain;

    #[ORM\Column(name: 'text_key', length: 255)]
    private string $key;

    #[ORM\Column(type: Types::TEXT)]
    private string $value;

    #[ORM\Column(type: Types::TEXT)]
    private string $original;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** Moved by every change: a page sends back the one it read, and a later one refuses the save. */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    public function __construct(?Company $company, TextAddress $address, string $value, string $original, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->language = $address->language;
        $this->domain = $address->domain;
        $this->key = $address->key;
        $this->value = $value;
        $this->original = $original;
        $this->updatedAt = $now;
    }

    /**
     * The original is the one shipped when the text was written, read again on every change.
     *
     * @return bool whether anything changed
     */
    public function change(string $value, string $original, \DateTimeImmutable $now): bool
    {
        if ($value === $this->value && $original === $this->original) {
            return false;
        }
        $this->value = $value;
        $this->original = $original;
        $this->updatedAt = $now;

        return true;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function address(): TextAddress
    {
        return new TextAddress($this->language, $this->domain, $this->key);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getOriginal(): string
    {
        return $this->original;
    }

    public function getVersion(): int
    {
        return $this->version;
    }
}
