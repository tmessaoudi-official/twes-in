<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform\Payload;

/**
 * The version an editable resource answers and its revision sends back: a `version` property read with the record and
 * written in this group, which only the PUT takes, so a creation never sends one and a revision always does
 * (tests/Architecture/RecordVersionsTest). A version saved since answers 409 (RecordChangedProblem).
 */
final class RecordVersion
{
    public const string REVISE = 'record:revise';
}
