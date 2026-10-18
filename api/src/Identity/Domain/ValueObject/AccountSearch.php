<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Domain\ValueObject;

use Symfony\Component\Uid\Uuid;

/**
 * What the platform's accounts list asks for: words found in an address or a name, whatever their case and accents, the
 * choices that narrow it, and the order, always ending on the address so a page never shifts. The accounts left out are
 * never found, nor counted: the demo's throwaway owners, which are no operator's concern.
 */
final readonly class AccountSearch
{
    public const array SORTS = ['active', 'platformOperator', 'createdAt', 'displayName', 'email'];

    /**
     * @param array<string, 'asc'|'desc'> $order   one of SORTS per key, in the order it applies
     * @param list<Uuid>                  $leftOut the accounts never found
     */
    public function __construct(
        public ?string $text = null,
        public ?bool $active = null,
        public ?bool $platformOperator = null,
        public array $order = [],
        public array $leftOut = [],
    ) {
    }

    /** @param list<Uuid> $accounts */
    public function leavingOut(array $accounts): self
    {
        return new self($this->text, $this->active, $this->platformOperator, $this->order, [...$this->leftOut, ...$accounts]);
    }
}
