<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Domain;

/** An expense or an expense category refused by its own rules; names the field, such as `amountNet`. */
final class InvalidExpense extends \DomainException
{
    /**
     * The reason names the text a screen and an import say it with (`import.rejections.*`), and `params` that text's
     * values; a refusal with none is said « Valeur refusée. ».
     *
     * @param array<string, string|int> $params
     */
    public function __construct(
        public readonly string $field,
        string $message,
        public readonly ?string $reason = null,
        public readonly array $params = [],
    ) {
        parent::__construct($message);
    }

    /** The same refusal named under what holds the field, such as `lines[1]`. */
    public function within(string $parent): self
    {
        return new self($parent.'.'.$this->field, $this->getMessage(), $this->reason, $this->params);
    }
}
