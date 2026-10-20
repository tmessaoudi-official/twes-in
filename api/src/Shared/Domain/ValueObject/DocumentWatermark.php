<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

/**
 * How a document's state (« BROUILLON », « DUPLICATA ») prints across its pages, as the company sets it in its design:
 * how strongly, how many times a page, and how the copies sit. The default is the look documents always printed in.
 * The word shrinks as it is repeated, so that no copy runs into another in any language.
 */
final readonly class DocumentWatermark
{
    /** @var list<int> how many times a page it may print */
    public const array COPIES = [1, 3, 6, 12];

    /** Grouped copies are lines this many times the word's size apart, which leaves room between them. */
    public const float GROUPED_LINE_HEIGHT = 1.4;

    /** The word's size in points for each count: one copy keeps the size documents always printed it in. */
    private const array WORD_SIZE_PT = [1 => 96, 3 => 48, 6 => 32, 12 => 22];

    /** Columns and rows of the page a spread count fills, each cell holding one copy. */
    private const array GRID = [1 => [1, 1], 3 => [1, 3], 6 => [2, 3], 12 => [3, 4]];

    public function __construct(
        public WatermarkIntensity $intensity = WatermarkIntensity::Normal,
        public int $copies = 1,
        public WatermarkSpread $spread = WatermarkSpread::Grouped,
    ) {
        if (!\in_array($copies, self::COPIES, true)) {
            throw new \InvalidArgumentException(\sprintf('A watermark prints %s times a page, not %d.', implode(', ', self::COPIES), $copies));
        }
    }

    /** The share of red the word is drawn in, written as the stylesheet takes it: « .14 ». */
    public function opacity(): string
    {
        return \sprintf('.%02d', $this->intensity->percent());
    }

    public function wordSizePt(): int
    {
        return self::WORD_SIZE_PT[$this->copies];
    }

    /** @return array{int, int} the columns and rows a spread watermark fills */
    public function grid(): array
    {
        return self::GRID[$this->copies];
    }

    /** @return array{intensity: string, copies: int, spread: string} */
    public function toArray(): array
    {
        return ['intensity' => $this->intensity->value, 'copies' => $this->copies, 'spread' => $this->spread->value];
    }

    /**
     * What a document kept; anything it did not keep, or kept bent, reads as the look documents always printed in.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $intensity = $data['intensity'] ?? null;
        $copies = $data['copies'] ?? null;
        $spread = $data['spread'] ?? null;

        return new self(
            WatermarkIntensity::tryFrom(\is_string($intensity) ? $intensity : '') ?? WatermarkIntensity::Normal,
            \is_int($copies) && \in_array($copies, self::COPIES, true) ? $copies : 1,
            WatermarkSpread::tryFrom(\is_string($spread) ? $spread : '') ?? WatermarkSpread::Grouped,
        );
    }
}
