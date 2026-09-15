<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Application;

/** A file that is not attached: empty, too large, of a type not accepted, or one too many for its subject. */
final class AttachmentRefused extends \DomainException
{
}
