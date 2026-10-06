<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\StepUp;

/** What was asked takes a proof given again in the last few minutes, and there is none, or it was spent. */
final class StepUpRequired extends \DomainException
{
}
