<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Domain;

use App\Shared\Domain\PrintSettings;

/**
 * How a quote prints as it was sent: its language, whether it ends with the « Bon pour accord » block, and the notes,
 * formats and design every document keeps. Kept with the quote, so it prints the same whatever the settings become.
 */
final readonly class QuotePrint
{
    public function __construct(
        public string $language,
        public bool $signatureBlock,
        public PrintSettings $print,
    ) {
    }

    /** @return array<string, string|bool> */
    public function toArray(): array
    {
        return ['language' => $this->language, 'signatureBlock' => $this->signatureBlock] + $this->print->toArray();
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $language = $data['language'] ?? null;
        $signatureBlock = $data['signatureBlock'] ?? null;
        if (!\is_string($language) || !\is_bool($signatureBlock)) {
            throw new \UnexpectedValueException('A quote\'s print settings hold no language or signature block.');
        }

        return new self($language, $signatureBlock, PrintSettings::fromArray($data));
    }
}
