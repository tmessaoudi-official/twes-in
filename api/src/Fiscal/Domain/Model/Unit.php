<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Model;

use App\Fiscal\Domain\Exception\InvalidFiscalValue;
use App\Fiscal\Domain\ValueObject\LanguageNames;
use App\Shared\Domain\Service\CompanyOwned;
use App\Shared\Domain\Service\TypedText;
use App\Tenancy\Domain\Model\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A unit a company sells in. The code is a UN/ECE Recommendation 20 code, which an EN 16931 invoice line carries
 * (BT-130), so it is fixed once created; the name is the company's own wording, and a document in a language the unit
 * names prints that language's.
 */
#[ORM\Entity]
#[ORM\Table(name: 'unit')]
#[ORM\UniqueConstraint(name: 'uniq_unit_company_code', columns: ['company_id', 'code'])]
class Unit implements CompanyOwned
{
    public const string CODE = '/^[A-Z0-9]{2,3}$/';
    /** A quantity is NUMERIC(14,3). */
    public const int MAX_DECIMALS = 3;
    private const int NAME_MAX = 60;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 3)]
    private string $code;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name;

    /**
     * Null for a unit created before units were named by language, until the next start gives it its preset's names.
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $names = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $decimals = 0;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** Doctrine counts each save, so an editor of an older version is told rather than overwriting it. */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    private function __construct(Company $company, string $code, \DateTimeImmutable $now)
    {
        if (1 !== preg_match(self::CODE, $code)) {
            throw new InvalidFiscalValue('code', \sprintf('"%s" is not a UN/ECE Recommendation 20 code: two or three capital letters or digits.', $code));
        }
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->code = $code;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** @param array<array-key, mixed> $names the name in other languages, by language */
    public static function create(Company $company, string $code, string $name, int $decimals, int $sortOrder, \DateTimeImmutable $now, array $names = []): self
    {
        $unit = new self($company, $code, $now);
        $unit->apply($name, $names, $decimals, true, $sortOrder);

        return $unit;
    }

    /**
     * @param array<array-key, mixed>|null $names null keeps them as they are
     *
     * @return bool whether anything changed
     */
    public function revise(string $name, int $decimals, bool $isActive, int $sortOrder, \DateTimeImmutable $now, ?array $names = null): bool
    {
        $before = [$this->name, $this->getNames(), $this->decimals, $this->isActive, $this->sortOrder];
        $this->apply($name, $names, $decimals, $isActive, $sortOrder);
        if ($before === [$this->name, $this->getNames(), $this->decimals, $this->isActive, $this->sortOrder]) {
            return false;
        }
        $this->updatedAt = $now;

        return true;
    }

    /** The name a document in that language prints. */
    public function nameIn(string $language): string
    {
        return $this->getNames()[$language] ?? $this->name;
    }

    /** Whether the unit is from before units were named by language and has not been given its names yet. */
    public function awaitsLanguageNames(): bool
    {
        return null === $this->names;
    }

    /**
     * Names a unit from before units were named by language, once: what a company wrote since is never overwritten.
     *
     * @param array<array-key, mixed> $names
     *
     * @return bool whether it was given them
     */
    public function giveLanguageNames(array $names): bool
    {
        if (!$this->awaitsLanguageNames()) {
            return false;
        }
        $this->names = LanguageNames::of($names, self::NAME_MAX);

        return true;
    }

    /** @param array<array-key, mixed>|null $names null keeps them as they are */
    private function apply(string $name, ?array $names, int $decimals, bool $isActive, int $sortOrder): void
    {
        $name = TypedText::trimmed($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidFiscalValue('name', \sprintf('A unit name has between 1 and %d characters.', self::NAME_MAX));
        }
        if ($decimals < 0 || $decimals > self::MAX_DECIMALS) {
            throw new InvalidFiscalValue('decimals', \sprintf('A quantity carries between 0 and %d decimals.', self::MAX_DECIMALS));
        }
        if ($sortOrder < 0) {
            throw new InvalidFiscalValue('sortOrder', 'A sort order is never negative.');
        }
        $names = null === $names ? $this->names : LanguageNames::of($names, self::NAME_MAX);
        $this->name = $name;
        $this->names = $names;
        $this->decimals = $decimals;
        $this->isActive = $isActive;
        $this->sortOrder = $sortOrder;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return array<string, string> */
    public function getNames(): array
    {
        $names = $this->names ?? [];
        ksort($names, \SORT_STRING);

        return $names;
    }

    public function getDecimals(): int
    {
        return $this->decimals;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
