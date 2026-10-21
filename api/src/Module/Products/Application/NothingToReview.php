<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

/** A product whose margin no receipt moved since its price was set: there is no price to adjust or keep. */
final class NothingToReview extends \RuntimeException
{
}
