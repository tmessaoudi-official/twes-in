<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

/** How a column holds the record it names. */
enum RecordLink: string
{
    /** A uuid column, with or without a foreign key. */
    case Id = 'id';
    /** A text column holding `<company id>/<record id>`, as a setting's level names its record. */
    case CompanyPath = 'company_path';
    /** A JSON array of ids (a customer's default taxes). */
    case IdList = 'id_list';
}
