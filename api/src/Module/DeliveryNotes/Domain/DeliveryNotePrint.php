<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Domain;

use App\Shared\Domain\PrintSettings;

/**
 * How a delivery note was printed when it was validated: its language, whether it showed prices and the reception
 * block, and the notes and formats every document keeps. Kept with the note, so a cancelled note is stamped on what
 * it said.
 */
final readonly class DeliveryNotePrint
{
    public function __construct(
        public string $language,
        public bool $showPrices,
        public bool $receptionBlock,
        public PrintSettings $print,
    ) {
    }

    /** @return array<string, string|bool> */
    public function toArray(): array
    {
        return ['language' => $this->language, 'showPrices' => $this->showPrices, 'receptionBlock' => $this->receptionBlock] + $this->print->toArray();
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $language = $data['language'] ?? null;
        $showPrices = $data['showPrices'] ?? null;
        $receptionBlock = $data['receptionBlock'] ?? null;
        if (!\is_string($language) || !\is_bool($showPrices) || !\is_bool($receptionBlock)) {
            throw new \UnexpectedValueException('A delivery note\'s print settings hold no language, prices or reception block.');
        }

        return new self($language, $showPrices, $receptionBlock, PrintSettings::fromArray($data));
    }
}
