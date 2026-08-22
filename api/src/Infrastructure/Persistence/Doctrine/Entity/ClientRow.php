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

/**
 * A client ROW — the mapped counterpart of {@see \Twes\Domain\Client\Client}.
 *
 * A SEPARATE class from the aggregate, for the reason `CLAUDE.md` § Architecture gives and {@see DocumentRow}
 * repeats: every domain type here is `final readonly` with mutators that return a new instance, and Doctrine's
 * unit of work is the opposite by construction — one mutable instance per row, diffed against a snapshot.
 * Mapping the domain type directly would be insert-only and would fight the ORM whichever driver was chosen,
 * which is why the driver was never the real argument.
 *
 * **THE ADDRESS IS FIVE FLAT COLUMNS RATHER THAN AN `#[ORM\Embedded]`.** An embeddable would express the
 * value object faithfully and buys nothing here: the repository writes with DBAL, so it never constructs this
 * entity to save, and the one thing this mapping IS for — `doctrine:schema:validate` agreeing with the
 * migration — works identically either way. What flat columns keep is that the mapping reads exactly like the
 * `CREATE TABLE`, which is the property that makes a drift visible at a glance.
 *
 * **This table is TENANT-OWNED.** A client list is commercially sensitive on its own — it is who a
 * competitor's customers are — so it carries the three RLS statements from `policySqlFor()` like every other
 * tenant-owned table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'client')]
class ClientRow
{
    /**
     * The tenant, and the leading half of the primary key.
     *
     * Leading rather than merely present: uniqueness is checked with row security BYPASSED, so a key omitting
     * the tenant is enforced across every tenant at once — which for a client id would be an existence oracle
     * over every tenant's client list.
     */
    #[ORM\Id]
    #[ORM\Column(name: 'company_id', type: 'uuid')]
    public Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    public Uuid $id;

    /**
     * EN 16931's BT-44, the buyer name — the one field an invoice cannot be addressed without.
     *
     * `text` rather than `varchar(n)`: the domain bounds it at `Client::MAX_NAME_LENGTH` and the migration
     * repeats that bound as a CHECK derived from the same constant, so the column type adds nothing but a
     * second number to keep in step. `FixedCharge::MAX_LABEL_LENGTH` makes the same call for the same reason.
     */
    #[ORM\Column(type: 'text')]
    public string $name;

    /**
     * BT-48, the buyer's VAT identifier — nullable, because not every buyer is registered for VAT.
     *
     * `varchar(32)` here because the length IS the constraint: the format deliberately is not checked (see
     * `Client::MAX_TAX_IDENTIFIER_LENGTH`, which argues that refusing an unfamiliar format makes a legitimate
     * client unrepresentable).
     */
    #[ORM\Column(name: 'tax_identifier', type: 'string', length: 32, nullable: true)]
    public ?string $taxIdentifier;

    /**
     * BG-8's five parts, ALL-OR-NOTHING as a group.
     *
     * Nullable individually because the whole address is optional, but the migration's
     * `client_address_is_whole` CHECK is what makes a HALF address unrepresentable — a city with no country is
     * an address nobody can post to and no Peppol profile will accept. The domain states the same rule in
     * {@see \Twes\Domain\Client\PostalAddress}; the CHECK is what stops a hand-written `INSERT` from
     * disagreeing with it.
     */
    #[ORM\Column(name: 'address_line_1', type: 'text', nullable: true)]
    public ?string $addressLine1;

    #[ORM\Column(name: 'address_line_2', type: 'text', nullable: true)]
    public ?string $addressLine2;

    #[ORM\Column(name: 'address_postcode', type: 'text', nullable: true)]
    public ?string $addressPostcode;

    #[ORM\Column(name: 'address_city', type: 'text', nullable: true)]
    public ?string $addressCity;

    /**
     * BT-55, ISO 3166-1 alpha-2.
     *
     * `CHAR(2)` fixes the LENGTH; the migration's `client_country_code_is_alpha_2` CHECK fixes the alphabet and
     * the CASE, which matters because the domain refuses lowercase rather than normalising it — so a row
     * inserted by hand must obey the same rule or it would read back as a value the domain cannot construct.
     */
    #[ORM\Column(name: 'address_country_code', type: 'string', length: 2, options: ['fixed' => true], nullable: true)]
    public ?string $addressCountryCode;

    /**
     * {@see DocumentNumberSequenceRow::__construct()} for why a Doctrine entity here has one.
     *
     * Every column is a parameter and none has a default, which is the forget-a-column guard those siblings
     * use: a column added later without being added here fails at construction rather than being written as
     * whatever PHP's uninitialised state would produce.
     */
    public function __construct(
        Uuid $companyId,
        Uuid $id,
        string $name,
        ?string $taxIdentifier,
        ?string $addressLine1,
        ?string $addressLine2,
        ?string $addressPostcode,
        ?string $addressCity,
        ?string $addressCountryCode,
    ) {
        $this->companyId = $companyId;
        $this->id = $id;
        $this->name = $name;
        $this->taxIdentifier = $taxIdentifier;
        $this->addressLine1 = $addressLine1;
        $this->addressLine2 = $addressLine2;
        $this->addressPostcode = $addressPostcode;
        $this->addressCity = $addressCity;
        $this->addressCountryCode = $addressCountryCode;
    }
}
