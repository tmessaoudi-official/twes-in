<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

/**
 * A second output of an issued invoice: the stored original is never reprinted, a copy says on its face that it is one.
 * A duplicate shows the document as issued; an up-to-date copy also stamps what it has become since.
 */
enum InvoiceCopy: string
{
    case Duplicate = 'duplicate';
    case UpToDate = 'current';
}
