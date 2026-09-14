<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

/** Lays a delivery note out as one HTML page, its styles inline, in the page's language. */
interface DeliveryNoteTemplate
{
    public function html(DeliveryNotePage $page): string;
}
