<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

/**
 * One column of an import file (docs/SPEC.md § 7, 2026-09-17): what it is called in the template a person downloads,
 * what it fills in, and whether a row without it can be read at all.
 *
 * The template's header row holds the `key`, not the translated heading, and the key is the ONLY thing the importer
 * matches on: a heading is translated, and a heading in a header row would make a file filled in one language
 * unreadable in another, while a second, technical header line is a row people delete. The heading is what the screen
 * shows beside the download.
 */
final readonly class ImportColumn
{
    /**
     * @param string      $key      what the importer matches on, stable across languages
     * @param string      $heading  the translation key for what a person reads
     * @param bool        $required whether a row lacking a value here is rejected rather than imported
     * @param string|null $example  a value in the shape expected, shown BESIDE the download on screen and never
     *                              written into the file: an example row in the template is imported as a real
     *                              customer by whoever forgets to delete it
     * @param string|null $note     a translation key for what a person needs to know to fill it in: the values
     *                              accepted, the format of a date or a rate, or where a code comes from
     */
    public function __construct(
        public string $key,
        public string $heading,
        public bool $required = false,
        public ?string $example = null,
        public ?string $note = null,
    ) {
    }
}
