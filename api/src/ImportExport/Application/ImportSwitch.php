<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

/**
 * A choice a subject offers for one run of its file, off unless the person ticks it: « Recompter », « Ignorer les
 * quantités de ce fichier ». The screen shows the subject's switches beside the mode and sends each ticked one by key.
 */
final readonly class ImportSwitch
{
    /**
     * @param string      $key   what the request names it by, stable across languages
     * @param string      $label a key of the screen's catalogue
     * @param string|null $note  a key of the screen's catalogue saying when to tick it
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $note = null,
    ) {
    }
}
