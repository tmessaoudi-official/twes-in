<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\DeliveryNotes\Application\DeliveryNotePage;
use App\Module\DeliveryNotes\Application\DeliveryNoteTemplate;

/** Keeps every page it was asked to lay out and answers a line naming it. */
final class RecordingDeliveryNoteTemplate implements DeliveryNoteTemplate
{
    /** @var list<DeliveryNotePage> */
    public array $pages = [];

    public function html(DeliveryNotePage $page): string
    {
        $this->pages[] = $page;

        return \sprintf('<p>%s %s</p>', $page->note->getNumber() ?? 'draft', $page->watermark ?? 'clean');
    }
}
