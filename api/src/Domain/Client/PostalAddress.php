<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Client;

/**
 * Where a client is — EN 16931's **BG-8 BUYER POSTAL ADDRESS**, as a value object.
 *
 * **THE FIELD SET IS DERIVED FROM THE STANDARD, NOT INVENTED**, which is licensing invariant 2's sanctioned
 * input: *"the standards an invoicing product must implement (EN 16931, UBL, CII, Factur-X, Peppol BIS)"* are
 * a legitimate source where upstream's code is not. BG-8 carries a street line (BT-50), an additional line
 * (BT-51), a city (BT-52), a postcode (BT-53) and a country code (BT-55). This class is those five and nothing
 * else — no region, no county, no `address_3`, because a column no code reads is what
 * `Version20260820120000` argues against at length and what a later e-invoicing wave can add with a migration
 * when a profile actually demands it.
 *
 * **ALL-OR-NOTHING, AND THAT IS THE WHOLE REASON THIS IS A TYPE RATHER THAN FIVE COLUMNS ON `Client`.** A
 * client legitimately has NO address — it is created the moment someone types a name, long before anyone asks
 * where to post an invoice — but a HALF address is not a state anybody wants: a city with no country is an
 * address nobody can post to and no Peppol profile will accept. Absence is therefore modelled by the address
 * being `null` on {@see Client}, never by a `PostalAddress` whose required parts are blank, and this
 * constructor is what makes the half-state unrepresentable rather than merely discouraged.
 *
 * **REQUIRED HERE ≠ REQUIRED TO ISSUE.** What this type refuses is an INCOHERENT address. What an issued
 * document needs is a different and stricter question — EN 16931 makes BG-8 mandatory on an invoice — and it
 * belongs to the wave that implements the e-invoicing profiles, as a check at ISSUE time against the client
 * the document is for. Enforcing it here instead would make a client unrepresentable until someone had its
 * full address, which is not how anybody enters a client.
 *
 * `readonly` with public properties rather than accessors, matching {@see \Twes\Domain\Document\DocumentIdentity}:
 * there is no invariant to protect beyond the constructor, and a getter per field would be ceremony.
 */
final readonly class PostalAddress
{
    /**
     * The longest any one part may be — a **derived** bound, flagged as such because no plan rules it.
     *
     * The same reasoning as `FixedCharge::MAX_LABEL_LENGTH`: a write endpoint is where an unbounded
     * client-supplied string becomes a real one. 140 rather than 64 because these ARE prose — a street line is
     * written by a human for a postal service, not an identifier — and comfortably above what any postal
     * format uses. Unlike `DocumentLine::MAX_SCALE` **no migration follows from it**: the columns are `text`,
     * which accepts everything the domain accepts, so this is a refusal we impose rather than one the schema
     * imposes on us.
     */
    public const int MAX_PART_LENGTH = 140;

    /**
     * ISO 3166-1 **alpha-2**, uppercase, and nothing else.
     *
     * **SHAPE-ONLY, AND THE ASYMMETRY WITH `Currency` IS DELIBERATE RATHER THAN AN OVERSIGHT.** `Currency`
     * embeds the ISO 4217 table and refuses an unknown code, because a currency code is LOAD-BEARING FOR
     * ARITHMETIC — it carries the minor-unit scale, so an unknown code silently produces wrong money, and TND
     * having three decimals is the reason this project has a `Money` type at all. A country code computes
     * nothing. It is transcribed onto a document, so a well-formed-but-unassigned code such as `XX` produces a
     * document a human can see is wrong, where an unknown currency produces a number nobody can see is wrong.
     *
     * The consequence is stated rather than hidden: this accepts `XX`, `ZZ` and the other unassigned
     * combinations. **Validating against the real code list belongs to the e-invoicing wave**, where Peppol
     * publishes the authoritative list and validates against it anyway — embedding a second copy here now
     * would be a list to maintain with no caller that reads it.
     *
     * Lowercase is REFUSED rather than normalised, for the reason
     * {@see \Twes\Domain\Shared\Identifier} refuses an uppercase UUID: two spellings of one value compare
     * unequal as strings, and this one is grouped on and reported per country. Normalising input is a
     * transport concern; the domain states the rule. `/D` for the same reason it is on the identifier pattern —
     * without it `"FR\n"` is accepted, which is a second spelling of `FR`.
     */
    private const string COUNTRY_CODE = '/^[A-Z]{2}$/D';

    public string $line1;
    public ?string $line2;
    public ?string $postcode;
    public string $city;

    /**
     * @param string $line1 the street line — BT-50
     * @param null|string $line2 an additional line, e.g. a building or floor — BT-51
     * @param null|string $postcode BT-53, optional because some countries have none: Ireland had no general
     *                              postcode system until 2015, and refusing a blank one would make those
     *                              clients unrepresentable
     * @param string $city BT-52
     * @param string $countryCode BT-55, ISO 3166-1 alpha-2
     *
     * @throws \InvalidArgumentException if a required part is blank, any part is longer than
     *                                   {@see self::MAX_PART_LENGTH}, or the country code is not alpha-2
     */
    public function __construct(
        string $line1,
        ?string $line2,
        ?string $postcode,
        string $city,
        public string $countryCode,
    ) {
        // TRIMMED ON STORE, not only on validate -- the defect `FixedCharge`'s constructor records, where
        // `'  stamp_duty  '` was validated trimmed and stored padded. ' Paris' and 'Paris' are not two cities.
        $this->line1 = self::required($line1, 'street line');
        $this->city = self::required($city, 'city');

        // BLANK BECOMES ABSENT. '', '   ' and null all mean "there is no second line", and keeping them apart
        // would put three spellings of one fact in a column -- the same argument the identifier rule makes about
        // two spellings of one id, applied to absence. A client edited through a web form sends '' for a field
        // somebody cleared, so this is the ordinary path and not an exotic one.
        $this->line2 = self::optional($line2, 'additional address line');
        $this->postcode = self::optional($postcode, 'postcode');

        if (1 !== preg_match(self::COUNTRY_CODE, $countryCode)) {
            throw new \InvalidArgumentException(\sprintf(
                'Country code "%s" is not an ISO 3166-1 alpha-2 code. Two uppercase letters are expected — "FR", '
                . '"TN" — and lowercase is refused rather than normalised, because two spellings of one country '
                . 'compare unequal as strings and this value is grouped on. Normalising what a caller sent is the '
                . 'transport layer\'s job.',
                $countryCode,
            ));
        }
    }

    /** A part that must be present: trimmed, non-blank, bounded. */
    private static function required(string $value, string $what): string
    {
        $value = trim($value);

        if ('' === $value) {
            throw new \InvalidArgumentException(\sprintf(
                'A postal address needs a %s. An address is stored ALL-OR-NOTHING — a client with no address at '
                . 'all is an ordinary state and is modelled by having no address object, but a half address is '
                . 'one nobody can post to and no e-invoicing profile will accept.',
                $what,
            ));
        }

        return self::bounded($value, $what);
    }

    /** A part that may be absent: blank normalises to null, and anything present is bounded. */
    private static function optional(?string $value, string $what): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : self::bounded($value, $what);
    }

    /**
     * MEASURED IN CHARACTERS, NOT BYTES.
     *
     * `strlen()` would make the bound depend on the alphabet — an Arabic address is two bytes per character, a
     * `é` is two — and an address refused for being in the wrong script is a defect rather than a bound.
     * `mb_strlen` with an EXPLICIT encoding rather than the ambient default, which is configuration.
     */
    private static function bounded(string $value, string $what): string
    {
        $length = mb_strlen($value, 'UTF-8');

        if ($length > self::MAX_PART_LENGTH) {
            throw new \InvalidArgumentException(\sprintf(
                'The %s is %d characters; at most %d are allowed. The bound counts CHARACTERS rather than bytes, '
                . 'so it is the same limit in every script.',
                $what,
                $length,
                self::MAX_PART_LENGTH,
            ));
        }

        return $value;
    }
}
