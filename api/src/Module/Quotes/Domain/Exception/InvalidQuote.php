<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Domain\Exception;

/** A quote refused by its own rules or its company's setup; names the field, such as `lines[0].quantity`. */
final class InvalidQuote extends \DomainException
{
    /**
     * @param string|null               $reason a stable code a screen translates, when the field does not say on its own what is wrong
     * @param array<string, string|int> $params that text's parameters
     */
    public function __construct(
        public readonly string $field,
        string $message,
        public readonly ?string $reason = null,
        public readonly array $params = [],
    ) {
        parent::__construct($message);
    }

    /** The same refusal, its field named under a parent: `quantity` within `lines[2]` is `lines[2].quantity`. */
    public function within(string $parent): self
    {
        return new self($parent.'.'.$this->field, $this->getMessage(), $this->reason, $this->params);
    }
}
