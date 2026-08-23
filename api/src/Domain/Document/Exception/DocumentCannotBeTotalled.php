<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Document\Exception;

use Twes\Domain\Money\Exception\InvalidMoneyAmount;

/**
 * Every figure on this document is individually representable and their SUM is not.
 *
 * ## Why this needs a type of its own, when the message was already right
 *
 * `Invoice::totallable()` has raised this refusal since the aggregate was written, with a message that explains
 * itself well. What it did NOT have was a type: it threw a bare `\InvalidArgumentException`, and at the transport
 * a bare `\InvalidArgumentException` escaping the handler is indistinguishable from the one
 * `InvoiceMapper` raises for a corrupt or unrepresentable COLUMN — which is OUR fault and must stay a 500.
 * `CreateInvoiceProcessor` therefore could not catch it without re-admitting the mapper's, which is the defect
 * three certification rounds removed from three separate classes. So the caller got a **500** for typing two
 * large-but-legal amounts (round 4, R4C-2: per-line overflow 422, two-line SUM overflow 500, charge overflow 500).
 *
 * A type is what lets the processor answer 422 for THIS and 500 for the mapper's, which is exactly the shape
 * {@see UnknownClient} established: the fix for a refusal the wide catch cannot safely cover is its own arm, not a
 * wider catch.
 *
 * ## `\InvalidArgumentException` rather than `\DomainException`, unlike `UnknownClient`
 *
 * Deliberate, and the two are not inconsistent. `UnknownClient` answers a question about the WORLD — whether a
 * client exists — which no inspection of the argument could settle. This one answers a question about the ARGUMENT:
 * the line or charge being added is out of range for the document it is being added to. `withLine()`,
 * `withFixedCharge()` and `withoutLine()` all document `@throws \InvalidArgumentException` for precisely this
 * case, and every one of those tags stays true because this IS one — so no caller's contract changes and no
 * existing `catch` stops working. Narrowing a thrown type to a subclass is the one direction that is always safe.
 *
 * ## It is user-fixable, which is what earns it a key
 *
 * `CLAUDE.md` § "Translation keys" test: *would a competent user, reading only this message, know what to change?*
 * Yes — remove something, or split the document, which is the same prescription `document.line_count_too_large`
 * gives. Its key is `document.total_too_large`, which has existed in all three locales since Wave 1 and until now
 * had no reachable refusal to resolve it.
 */
final class DocumentCannotBeTotalled extends \InvalidArgumentException
{
    public static function afterAdding(string $what, InvalidMoneyAmount $overflow): self
    {
        return new self(
            \sprintf(
                'Adding that %s would make this document impossible to total: %s. Every figure is individually '
                . 'in bounds — a sum of representable amounts can be unrepresentable — and refusing it here is '
                . 'what stops the document being ISSUED, its number consumed permanently from a gapless legal '
                . 'sequence, and its totals raising forever afterwards including once cancelled.',
                $what,
                $overflow->getMessage(),
            ),
            0,
            $overflow,
        );
    }
}
