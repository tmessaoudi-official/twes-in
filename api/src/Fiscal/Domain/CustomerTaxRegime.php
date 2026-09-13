<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A tax regime a customer may be under, as one fiscal preset defines it. Operator-owned: the seed writes it from the
 * preset file, no company edits it, and a customer will point at it (G4). A regime a preset no longer lists stays,
 * so a customer never loses the regime it was given.
 */
#[ORM\Entity]
#[ORM\Table(name: 'customer_tax_regime')]
#[ORM\UniqueConstraint(name: 'uniq_customer_tax_regime_preset_code', columns: ['fiscal_preset', 'code'])]
class CustomerTaxRegime
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 8)]
    private string $fiscalPreset;

    #[ORM\Column(length: 24)]
    private string $code;

    #[ORM\Column(length: 120)]
    private string $labelKey;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $excludedFamilies;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $mentionKey;

    #[ORM\Column]
    private int $sortOrder;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @param list<TaxFamily> $excludedFamilies */
    public function __construct(string $fiscalPreset, string $code, string $labelKey, array $excludedFamilies, ?string $mentionKey, int $sortOrder, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->fiscalPreset = $fiscalPreset;
        $this->code = $code;
        $this->labelKey = $labelKey;
        $this->excludedFamilies = self::values($excludedFamilies);
        $this->mentionKey = $mentionKey;
        $this->sortOrder = $sortOrder;
        $this->updatedAt = $now;
    }

    /**
     * Brings the row in line with the preset file.
     *
     * @param list<TaxFamily> $excludedFamilies
     *
     * @return bool whether anything changed
     */
    public function redefine(string $labelKey, array $excludedFamilies, ?string $mentionKey, int $sortOrder, \DateTimeImmutable $now): bool
    {
        $after = [$labelKey, self::values($excludedFamilies), $mentionKey, $sortOrder];
        if ([$this->labelKey, $this->excludedFamilies, $this->mentionKey, $this->sortOrder] === $after) {
            return false;
        }
        [$this->labelKey, $this->excludedFamilies, $this->mentionKey, $this->sortOrder] = $after;
        $this->updatedAt = $now;

        return true;
    }

    /**
     * @param list<TaxFamily> $families
     *
     * @return list<string>
     */
    private static function values(array $families): array
    {
        return array_map(static fn (TaxFamily $family) => $family->value, $families);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFiscalPreset(): string
    {
        return $this->fiscalPreset;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabelKey(): string
    {
        return $this->labelKey;
    }

    /** @return list<TaxFamily> */
    public function getExcludedFamilies(): array
    {
        return array_values(array_filter(array_map(static fn (string $family) => TaxFamily::tryFrom($family), $this->excludedFamilies)));
    }

    public function getMentionKey(): ?string
    {
        return $this->mentionKey;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }
}
