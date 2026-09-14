<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Domain;

/** A delivery note refused by its own rules or its company's setup; names the field, such as `lines[0].quantity`. */
final class InvalidDeliveryNote extends \DomainException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    /** The same refusal, its field named under a parent: `quantity` within `lines[2]` is `lines[2].quantity`. */
    public function within(string $parent): self
    {
        return new self($parent.'.'.$this->field, $this->getMessage());
    }
}
