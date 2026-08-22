<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Twes\Domain\Document\VatRoundingPoint;

/**
 * One company's document settings, as a ROW.
 *
 * The mapped counterpart of {@see \Twes\Domain\Settings\CompanySettings}, and a SEPARATE class from it for the
 * reason `CLAUDE.md` § Architecture gives: every domain type here is `final readonly` with mutators that
 * return a new instance, and Doctrine's unit of work is the opposite by construction — one mutable instance
 * per row, diffed against a snapshot. Mapping the domain type directly would be insert-only and would fight
 * the ORM whichever driver was chosen, which is why the driver was never the real argument.
 *
 * **`company_id` IS THE ENTIRE PRIMARY KEY, and that is deliberate rather than an omission.** Every other
 * tenant-owned table here carries `PRIMARY KEY (company_id, id)`; this one holds exactly one row per tenant,
 * and the key is what enforces that. A surrogate `id` would permit two settings rows for one tenant and
 * every read would then have to choose between them. The reason the composite rule exists elsewhere —
 * uniqueness is checked with row security BYPASSED, so a key omitting the tenant is enforced across all
 * tenants — is satisfied here rather than waived, because the tenant column IS the key. See
 * `Version20260820120000` for the same argument at the schema level.
 *
 * **This table is TENANT-OWNED**, so it carries the three RLS statements from `policySqlFor()` like every
 * other one. A leak here is not merely a configuration disclosure: `default_vat_rounding_point` decides how
 * much tax a document declares, so a tenant able to write another's settings can change the figures on
 * documents that tenant has not yet issued.
 */
#[ORM\Entity]
#[ORM\Table(name: 'company_settings')]
class CompanySettingsRow
{
    #[ORM\Id]
    #[ORM\Column(name: 'company_id', type: 'uuid')]
    public Uuid $companyId;

    /**
     * How wide a NEW document number is rendered — `0000041` at 7.
     *
     * `smallint` in the schema with `CHECK (BETWEEN 1 AND NumberPattern::MAX_WIDTH)`, so the column refuses
     * exactly what `NumberPattern::padded()` refuses. Mapped as `smallint` rather than `integer` so
     * `doctrine:schema:validate` agrees with the migration; the PHP type is `int` either way, because PHP has
     * no narrower one.
     */
    #[ORM\Column(name: 'number_pattern_width', type: 'smallint')]
    public int $numberPatternWidth;

    /**
     * `VatRoundingPoint`'s backed value — the rounding point a NEW document is created with.
     *
     * A string rather than a mapped enum type, matching `DocumentRow`'s treatment of the same value: the
     * translation between the wire/column form and the domain enum belongs in the repository, which is the
     * one place that already has to refuse a value the database somehow holds and the enum does not know.
     *
     * **NOT the rounding point any EXISTING document is computed with.** Each document persists its own, so a
     * company changing this cannot restate a document a client already holds — the byte-identical-re-download
     * guarantee, and the reason the 2026-08-07 ruling put this on the company rather than in the request.
     */
    // THE LENGTH IS DERIVED, NOT CHOSEN. It read `length: 32` while `Version20260820120000` computed
    // `varchar(14)` from `VatRoundingPoint::cases()` — the mapping and the schema disagreeing about one column,
    // which `doctrine:schema:validate --skip-sync` cannot detect because `--skip-sync` is precisely what stops
    // it looking at a database. Both sides now read the same constant.
    #[ORM\Column(
        name: 'default_vat_rounding_point',
        type: 'string',
        length: VatRoundingPoint::MAX_BACKED_VALUE_LENGTH,
    )]
    public string $defaultVatRoundingPoint;

    /**
     * {@see DocumentNumberSequenceRow::__construct()} for why a Doctrine entity here has one.
     *
     * Every column is a parameter and none has a default, which is the forget-a-column guard those siblings
     * use: a column added later without being added here fails at construction rather than being written as
     * whatever PHP's uninitialised state would produce. There is no defaults-shaped constructor on purpose —
     * "what an unconfigured tenant gets" is a DOMAIN decision that lives on
     * {@see \Twes\Domain\Settings\CompanySettings::defaults()}, and a second copy of it here is exactly the
     * duplication that class's docblock argues against.
     */
    public function __construct(Uuid $companyId, int $numberPatternWidth, string $defaultVatRoundingPoint)
    {
        $this->companyId = $companyId;
        $this->numberPatternWidth = $numberPatternWidth;
        $this->defaultVatRoundingPoint = $defaultVatRoundingPoint;
    }
}
