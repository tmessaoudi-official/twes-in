<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Shared;

/**
 * What a persisted identifier looks like in this domain — **the ONE definition of that rule**.
 *
 * **EXTRACTED FROM `DocumentIdentity::isWellFormedId()`, and the reason is that the rule was never about
 * documents.** That method's own docblock already called itself *"the ONE definition"*, having been extracted
 * once before when three copies of the pattern had accumulated. The second extraction is this one, and it was
 * forced by the first type outside `Domain\Document` needing the same rule: a `Client` id obeys it identically,
 * and a client validating itself through a class named `DocumentIdentity` would either import the document
 * namespace for a rule that has nothing to do with documents, or — the outcome this file exists to prevent —
 * grow a fourth copy of the pattern. `CLAUDE.md` § Gotchas records what happens to a duplicated condition:
 * the `/D` gets fixed in one of them.
 *
 * **A REGEX, not a library.** `Domain/` has ZERO Composer dependencies and
 * `scripts/gates/layer-dependencies.php` enforces that with an empty allowlist — `DocumentIdentity`'s docblock
 * records the gate catching its own author reaching for `Symfony\Component\Uid\Uuid` here, and the tempting
 * argument that came with it. So the check has to be one PHP can make unaided. `Infrastructure/` converts at
 * the boundary, which is where `symfony/uid` belongs.
 *
 * **THREE PROPERTIES ARE LOAD-BEARING, and each is a real defect rather than a hypothetical one** — every one
 * of them survived the whole suite as a mutant before `IdentifierTest` pinned it:
 *
 * - **The `/D` modifier.** Without it PCRE's `$` also matches BEFORE a final newline, so `"…e1f0\n"` is
 *   accepted — two unequal strings for one id, and this value is used as a key.
 * - **The `^` anchor.** An unanchored pattern accepts an id with a payload PREPENDED, and this value reaches a
 *   `WHERE` clause.
 * - **Case sensitivity.** Uppercase and the braced or urn forms are refused rather than NORMALISED, for the
 *   same reason: two spellings of one id compare unequal as strings.
 *
 * **A PREDICATE rather than a throwing factory.** Its callers want different things from a refusal — the
 * constructors want their own exception and their own message, and `InvoiceProvider` wants to answer 404 with
 * no exception at all. That last one is not a preference: a `catch` wide enough to turn a malformed id into a
 * 404 is also wide enough to swallow a HYDRATION failure from corrupt column data and answer 404 to it, making
 * a document that demonstrably exists vanish from the API with nobody told to investigate.
 */
final class Identifier
{
    /**
     * The canonical lowercase-hyphenated UUID form, and nothing else.
     *
     * Anchored at both ends with `/D`, and every group length is a CEILING as well as a floor — round 3 found
     * `{12}` → `{12,}` and `{8}` → `{8,}` each surviving the whole suite. Under the first, a 37-character id
     * reaches `WHERE id = :id` and PostgreSQL raises `invalid input syntax for type uuid`: a 500 where this
     * predicate exists to produce a 404.
     */
    private const string CANONICAL = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    /**
     * Not instantiable: this is a namespace for one rule, not a service.
     *
     * `RowHydrator` is the established precedent for the shape — `config/services.yaml` excludes it from
     * autowiring on exactly this ground — and a private constructor makes the intent enforceable rather than
     * conventional.
     */
    private function __construct() {}

    /** Is `$id` a canonical identifier? */
    public static function isWellFormed(string $id): bool
    {
        return 1 === preg_match(self::CANONICAL, $id);
    }
}
