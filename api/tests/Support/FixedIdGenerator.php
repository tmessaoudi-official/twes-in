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

use Twes\Domain\Shared\IdGenerator;

/**
 * An {@see IdGenerator} handing out a known, ascending series — so a test can assert WHICH id a handler used.
 *
 * The real generator is `UuidV7Generator`, whose output is unpredictable by design; a handler test that could not
 * name the id it expects would have to read it back out of the thing under test, which proves nothing about whether
 * the handler asked the port at all.
 *
 * The ids are canonical v7-shaped UUIDs rather than arbitrary strings, because `DocumentIdentity` refuses anything
 * that is not one — a fake producing `'id-1'` would fail construction and send a reader hunting for a bug in the
 * handler.
 */
final class FixedIdGenerator implements IdGenerator
{
    private int $issued = 0;

    /**
     * Every id this generator has handed out, in order.
     *
     * @var list<string>
     */
    public array $handedOut = [];

    public function nextIdentifier(): string
    {
        $id = \sprintf('0199a5b2-0000-7000-8000-%012d', ++$this->issued);
        $this->handedOut[] = $id;

        return $id;
    }
}
