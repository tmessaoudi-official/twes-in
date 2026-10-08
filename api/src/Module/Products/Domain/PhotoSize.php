<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

/** Which of a photo's pictures is wanted: the small copy for a line, the large one for a page, or the photo as sent. */
enum PhotoSize: string
{
    case Small = 'small';
    case Large = 'large';
    case Original = 'original';
}
