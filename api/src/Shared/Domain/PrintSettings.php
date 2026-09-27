<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * How a numbered document was printed when it was issued: the notes printed on it and how it writes days and figures.
 * Kept with the document, so a later render (a cancelled note stamped, a PDF first rendered after the renderer
 * failed) prints what the document said, whatever the settings say by then.
 */
final readonly class PrintSettings
{
    public function __construct(
        public string $printedNotes,
        /** A `presentation.date-format` choice; `auto` leaves it to the document's language. */
        public string $dateFormat,
        /** A `presentation.number-format` choice; `auto` leaves it to the document's language. */
        public string $numberFormat,
    ) {
    }

    /** @return array{printedNotes: string, dateFormat: string, numberFormat: string} */
    public function toArray(): array
    {
        return ['printedNotes' => $this->printedNotes, 'dateFormat' => $this->dateFormat, 'numberFormat' => $this->numberFormat];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(self::text($data, 'printedNotes'), self::text($data, 'dateFormat'), self::text($data, 'numberFormat'));
    }

    /** @param array<string, mixed> $data */
    private static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value)) {
            throw new \UnexpectedValueException(\sprintf('A document\'s print settings hold no "%s".', $key));
        }

        return $value;
    }
}
