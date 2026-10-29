<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\Phones;

/**
 * The platform's one policy for a phone of the right length outside the ranges its country hands out (ruled
 * 2026-10-29): « Refuser », its default, or « Garder et marquer À vérifier ». The screens read the same setting.
 */
interface PhonePolicy
{
    /** Whether such a number is kept, marked to check, rather than refused. */
    public function keepsOutsideRanges(): bool;
}
