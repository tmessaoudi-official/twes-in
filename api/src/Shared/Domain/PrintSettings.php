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
        /** Whether the total is also written out in words; a document issued before the setting existed has none. */
        public bool $amountInWords = false,
        /** Whether the seller's bank details are printed as the way to pay; a document issued before the setting existed has none. */
        public bool $howToPay = false,
    ) {
    }

    /** A delivery note asks for no payment: it carries goods, not a bill. */
    public function withoutHowToPay(): self
    {
        return new self($this->printedNotes, $this->dateFormat, $this->numberFormat, $this->amountInWords, false);
    }

    /** @return array{printedNotes: string, dateFormat: string, numberFormat: string, amountInWords: bool, howToPay: bool} */
    public function toArray(): array
    {
        return ['printedNotes' => $this->printedNotes, 'dateFormat' => $this->dateFormat, 'numberFormat' => $this->numberFormat, 'amountInWords' => $this->amountInWords, 'howToPay' => $this->howToPay];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(self::text($data, 'printedNotes'), self::text($data, 'dateFormat'), self::text($data, 'numberFormat'), true === ($data['amountInWords'] ?? false), true === ($data['howToPay'] ?? false));
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
