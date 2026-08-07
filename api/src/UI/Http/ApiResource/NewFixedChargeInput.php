<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\UI\Http\ApiResource;

use Symfony\Component\Validator\Constraints as Assert;
use Twes\Domain\Document\FixedCharge;

/**
 * One fixed charge on an invoice being created. **Input only** — the response side is {@see FixedChargeResource}.
 *
 * See {@see NewInvoiceLineInput} for why the numbers are declared `string`, why required-ness is the constructor's
 * job rather than a validator's, and why the real bounds stay in the domain.
 */
final readonly class NewFixedChargeInput
{
    public function __construct(
        /**
         * A STABLE IDENTIFIER, not display text — `stamp_duty`, not `Timbre fiscal`.
         *
         * {@see FixedCharge} states the reason and it is a correctness one rather than a convention: a document
         * rendered in Arabic must not carry a French label baked into its stored figures, so the label is a key the
         * translation layer resolves. The domain trims it and refuses an empty result.
         *
         * The bound is the domain's own {@see FixedCharge::MAX_LABEL_LENGTH}, referenced rather than repeated so the
         * edge and the invariant cannot disagree. **It is NOT the column's bound**, which is worth stating because
         * every other bound in this domain is: `document_charge.label` is `TEXT`, so the schema accepts anything.
         * This is a refusal we impose because an identifier long enough to be a sentence is being used as the wrong
         * thing.
         */
        #[Assert\NotBlank]
        #[Assert\Length(max: FixedCharge::MAX_LABEL_LENGTH)]
        public string $label,
        /** The charge amount, a decimal string in the invoice's currency. Negativity is refused by the domain. */
        #[Assert\Regex(pattern: '/^-?\d+(\.\d+)?$/D')]
        public string $amount,
    ) {}
}
