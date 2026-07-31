<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use Twes\Domain\Document\DocumentNumberSequence;
use Twes\Tests\Support\InMemoryDocumentNumberSequence;

/**
 * The reference double, held to the port's contract rather than trusted.
 *
 * Every other unit test in this directory drives {@see InMemoryDocumentNumberSequence} as a stand-in for a
 * database. If the double quietly violated the contract — gapped, restarted, shared a counter across types —
 * those tests would keep passing while asserting against behaviour production will not have. So the double is
 * itself a subject: it extends the same contract class the Postgres adapter will.
 */
#[CoversClass(InMemoryDocumentNumberSequence::class)]
final class InMemoryDocumentNumberSequenceTest extends DocumentNumberSequenceContract
{
    protected function sequence(): DocumentNumberSequence
    {
        return new InMemoryDocumentNumberSequence();
    }
}
