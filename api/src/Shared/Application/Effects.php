<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * What a request did beyond its subject, said in its answer to whoever made it (`Effect`). An effect said inside a unit
 * of work of Transactions::run() is kept only once that unit commits, so a rolled-back receipt never says the price it
 * would have moved; one said outside any unit is kept at once.
 */
interface Effects
{
    public function said(Effect $effect): void;
}
