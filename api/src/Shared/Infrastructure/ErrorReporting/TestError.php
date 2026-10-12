<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\ErrorReporting;

/** The error app:errors:test fails with, asked for on purpose: nothing broke. */
final class TestError extends \RuntimeException
{
}
