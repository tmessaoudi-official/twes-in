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
     * @param string|null        $setting a company setting holding where it starts: a switch, ticked or not on the screen;
     *                                    an answer of `$choices`, which a request naming none then takes
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $note = null,
        public array $choices = [],
        public ?string $setting = null,
    ) {
    }

    /**
     * The answer the request names, else the company's (`$default`, read from `$setting`) when it is one offered, else the
     * first; null when the request names one this switch does not offer.
     */
    public function answer(?string $named, ?string $default = null): ?string
    {
        if (null === $named) {
            return null !== $default && $this->offers($default) ? $default : ($this->choices[0]->key ?? null);
        }
        foreach ($this->choices as $choice) {
            if ($choice->key === $named) {
                return $named;
            }
        }

        return null;
    }

    private function offers(string $answer): bool
    {
        foreach ($this->choices as $choice) {
            if ($choice->key === $answer) {
                return true;
            }
        }

        return false;
    }
}
