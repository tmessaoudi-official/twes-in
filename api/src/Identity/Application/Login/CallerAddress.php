<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Login;

/** Where the request being answered comes from, as a password's lock keys it. */
interface CallerAddress
{
    /** An IPv4 address whole, an IPv6 one by its network (its first 64 bits), which one machine is given whole. */
    public function key(): string;
}
