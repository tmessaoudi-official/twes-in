<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One version of one legal page in one language, in Markdown. Versions are only ever added: a change is a new version,
 * which starts unvalidated until someone with the right to publish says it was checked (docs/SPEC.md § 7,
 * 2026-09-27). The platform's own, belonging to no company. A version the seed wrote was created by no one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'legal_text')]
#[ORM\Index(name: 'idx_legal_text_page_language', columns: ['page', 'language', 'created_at'])]
class LegalText
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 20, enumType: LegalPage::class)]
    private LegalPage $page;

    #[ORM\Column(length: 2, enumType: LegalLanguage::class)]
    private LegalLanguage $language;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    /** The date the page shows as its version. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $publishedOn;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validatedAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $validatedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $createdBy;

    private function __construct(LegalPage $page, LegalLanguage $language, string $body, ?string $createdBy, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->page = $page;
        $this->language = $language;
        $this->body = $body;
        $this->publishedOn = $now->setTime(0, 0);
        $this->createdAt = $now;
        $this->createdBy = $createdBy;
    }

    /** A new version, unvalidated, dated the day it was written; `$createdBy` is null for the seed's own. */
    public static function draft(LegalPage $page, LegalLanguage $language, string $body, ?string $createdBy, \DateTimeImmutable $now): self
    {
        return new self($page, $language, $body, $createdBy, $now);
    }

    public function validate(string $by, \DateTimeImmutable $now): void
    {
        $this->validatedBy = $by;
        $this->validatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPage(): LegalPage
    {
        return $this->page;
    }

    public function getLanguage(): LegalLanguage
    {
        return $this->language;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getPublishedOn(): \DateTimeImmutable
    {
        return $this->publishedOn;
    }

    public function isValidated(): bool
    {
        return null !== $this->validatedAt;
    }

    public function getValidatedAt(): ?\DateTimeImmutable
    {
        return $this->validatedAt;
    }

    public function getValidatedBy(): ?string
    {
        return $this->validatedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }
}
