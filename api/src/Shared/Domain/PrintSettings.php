<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * How a numbered document was printed when it was issued: the notes printed on it, how it writes days and figures,
 * and its design.
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
        /** Its layout, accent and logo size; a document issued before designs existed printed the classic way. */
        public DocumentDesign $design = new DocumentDesign(),
        /** Whether what the discounts took off is printed under the totals; a document issued before the setting existed has none. */
        public bool $savingsLine = false,
    ) {
    }

    /**
     * The « Vous économisez … » figure to print: what the discounts took off, when the document asked for the line and
     * something was taken off. A credit note's figures carry its sign, so it never prints one; nor does a document
     * issued before the figure was kept.
     */
    public function savingsPrinted(?string $savings): ?string
    {
        if (!$this->savingsLine || null === $savings || str_starts_with($savings, '-') || 1 !== preg_match('/[1-9]/', $savings)) {
            return null;
        }

        return $savings;
    }

    /** A delivery note asks for no payment: it carries goods, not a bill. */
    public function withoutHowToPay(): self
    {
        return new self($this->printedNotes, $this->dateFormat, $this->numberFormat, $this->amountInWords, false, $this->design, $this->savingsLine);
    }

    /** @return array{printedNotes: string, dateFormat: string, numberFormat: string, amountInWords: bool, howToPay: bool, savingsLine: bool, layout: string, accent: string} */
    public function toArray(): array
    {
        return ['printedNotes' => $this->printedNotes, 'dateFormat' => $this->dateFormat, 'numberFormat' => $this->numberFormat, 'amountInWords' => $this->amountInWords, 'howToPay' => $this->howToPay, 'savingsLine' => $this->savingsLine, ...$this->design->toArray()];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(self::text($data, 'printedNotes'), self::text($data, 'dateFormat'), self::text($data, 'numberFormat'), true === ($data['amountInWords'] ?? false), true === ($data['howToPay'] ?? false), DocumentDesign::fromArray($data), true === ($data['savingsLine'] ?? false));
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
