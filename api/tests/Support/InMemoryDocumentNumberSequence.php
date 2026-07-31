<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Support;

use Twes\Domain\Document\DocumentNumberSequence;
use Twes\Domain\Document\DocumentType;

/**
 * The reference in-memory counter — a TEST DOUBLE, and deliberately NOT in `src/`.
 *
 * An in-memory sequence in production restarts at 1 on every deploy and in every worker process, so it issues
 * **duplicate legal document numbers** — the worst outcome available to a numbering system, and worse than
 * having no implementation at all because it looks like it works. `InMemoryTenantContext` lives in `src/`
 * because a request context legitimately *is* in-memory; a gapless counter is not, so this one lives under
 * `tests/` where the container cannot wire it.
 *
 * It is the contract's reference behaviour, so it is held to the contract by
 * {@see \Twes\Tests\Unit\Document\InMemoryDocumentNumberSequenceTest} rather than trusted.
 */
final class InMemoryDocumentNumberSequence implements DocumentNumberSequence
{
    /** @var array<string, int> */
    private array $issued = [];

    /**
     * @param int|null $forcedCounter when set, returned for EVERY call — the only way to drive the allocator's
     *                                contract-violation branch, since a correct counter never reaches it
     */
    public function __construct(private readonly ?int $forcedCounter = null) {}

    public function allocateNext(DocumentType $type): int
    {
        if (null !== $this->forcedCounter) {
            return $this->forcedCounter;
        }

        // Keyed on `->value` rather than the enum or `->name`: the backed value is the stable identifier and is
        // what a real adapter's counter row will key on, so the double's key space matches production's.
        $key = $type->value;

        return $this->issued[$key] = ($this->issued[$key] ?? 0) + 1;
    }
}
