<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\Bulk;

/**
 * What a bulk action was sent no longer matches what the API would do now: an invoice was paid, credited or changed
 * since the person saw the preview. Nothing is done; the screen checks again and shows the new preview.
 */
final class BulkChanged extends \RuntimeException
{
}
