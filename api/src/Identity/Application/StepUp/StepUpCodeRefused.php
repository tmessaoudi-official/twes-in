<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\StepUp;

/** The password held, and the authenticator code asked beside it was missing, wrong or already spent. */
final class StepUpCodeRefused extends \DomainException
{
}
