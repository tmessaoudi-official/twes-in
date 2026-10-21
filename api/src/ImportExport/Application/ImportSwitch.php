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
 *
 * A switch given answers is a choice of one of them rather than a tick, the first taken unless the request names
 * another under `choices[<key>]`: « Prix TTC impossible à atteindre », just below, just above, or the row refused.
 */
final readonly class ImportSwitch
{
    /**
     * @param string             $key     what the request names it by, stable across languages
     * @param string             $label   a key of the screen's catalogue
     * @param string|null        $note    a key of the screen's catalogue saying when to tick it
     * @param list<ImportChoice> $choices its answers, the first the one taken when none is named; none for a tick
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $note = null,
        public array $choices = [],
    ) {
    }

    /** The answer the request names, the first when it names none, null when it names one this switch does not offer. */
    public function answer(?string $named): ?string
    {
        if (null === $named) {
            return $this->choices[0]->key ?? null;
        }
        foreach ($this->choices as $choice) {
            if ($choice->key === $named) {
                return $named;
            }
        }

        return null;
    }
}
