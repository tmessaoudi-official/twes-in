<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

/**
 * A controller whose answer carries a company's records out whole in one file: a list's export, a report's, a printed
 * selection of invoices, the month's withholding declaration. Under a support grant such a file is refused, as every
 * write is (SupportReadOnlyListener), while each record stays readable one at a time.
 *
 * Marked on the class rather than matched on its path, so a new export is refused by being one: BulkFilesTest requires
 * the mark on every controller that records `export.downloaded`.
 */
interface BulkFile
{
}
