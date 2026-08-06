<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Document;

/**
 * An `Invoice` together with the {@see DocumentIdentity} it was stored under.
 *
 * **A named type rather than `array{DocumentIdentity, Invoice}`**, which is what {@see \Twes\Infrastructure\
 * Persistence\Doctrine\InvoiceMapper} returns internally. A tuple is acceptable inside one Infrastructure class
 * whose two ends are eight lines apart; it is not acceptable on a domain port, where the return type is the contract
 * every caller reads. A shape expressed only in a `@return` annotation is checked by PHPStan and by nothing at
 * runtime, and `list($a, $b)` at the call site names neither element.
 *
 * **Why the two are not merged into one object.** `DocumentIdentity`'s own docblock records the ruling: `Invoice`
 * carries currency, state, number, lines and charges and has no identity at all, and moving `vatRoundingPoint` onto
 * it would make state out of what `totals()` takes as a PARAMETER — which is the mechanism that keeps
 * inclusive-versus-exclusive tax "a parameter, never a parallel class hierarchy". So they stay separate, and this
 * pairs them for the one operation that needs both: reading a document back.
 *
 * There is no factory and no validation, because there is nothing to validate: both members enforce their own
 * invariants on construction, and a pair of valid values is valid.
 */
final readonly class PersistedInvoice
{
    public function __construct(
        public DocumentIdentity $identity,
        public Invoice $invoice,
    ) {}
}
