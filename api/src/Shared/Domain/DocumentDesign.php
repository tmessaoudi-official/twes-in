<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * How a printed document looks (docs/SPEC.md § 7, 2026-09-21 19:20 and 2026-10-06 10:19): one of the built-in layouts
 * and the company's accent colour. The colours printed beside the accent are derived from it here, so that no accent a
 * company picks prints text that cannot be read: text on a band of the accent, and the accent as text on paper, keep
 * WCAG's 4.5:1. The font, the paper and the watermark are not part of a design.
 */
final readonly class DocumentDesign
{
    /** The ink documents printed in before they had an accent, which the classic layout keeps by default. */
    public const string DEFAULT_ACCENT = '#1f2328';

    private const string PAPER = '#ffffff';
    private const float READABLE = 4.5;

    /** The accent, lower case. */
    public string $accent;

    public function __construct(public DocumentLayout $layout = DocumentLayout::Classic, string $accent = self::DEFAULT_ACCENT)
    {
        // Written into the document's stylesheet: anything but a colour is refused, never escaped.
        if (1 !== preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            throw new \InvalidArgumentException(\sprintf('A document accent is a colour written #rrggbb, not "%s".', $accent));
        }
        $this->accent = strtolower($accent);
    }

    /** White or black, whichever reads better on a band of the accent: one of the two always reaches 4.5:1. */
    public function inkOnAccent(): string
    {
        return self::contrast(self::PAPER, $this->accent) >= self::contrast('#000000', $this->accent) ? self::PAPER : '#000000';
    }

    /** The accent as text or rules on paper, darkened just enough to be read there. */
    public function accentOnPaper(): string
    {
        $colour = $this->accent;
        while (self::contrast($colour, self::PAPER) < self::READABLE) {
            $colour = self::mix($colour, '#000000', 0.1);
        }

        return $colour;
    }

    /** The accent faint on paper, a tenth of it, for a band that carries text in the accent. */
    public function tint(): string
    {
        return self::mix(self::PAPER, $this->accent, 0.1);
    }

    /** WCAG's contrast ratio between two colours. */
    public static function contrast(string $one, string $other): float
    {
        [$light, $dark] = [self::luminance($one), self::luminance($other)];

        return (max($light, $dark) + 0.05) / (min($light, $dark) + 0.05);
    }

    /** @return array{layout: string, accent: string} */
    public function toArray(): array
    {
        return ['layout' => $this->layout->value, 'accent' => $this->accent];
    }

    /**
     * What a document kept; one issued before designs existed printed the classic way.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $layout = $data['layout'] ?? null;
        $accent = $data['accent'] ?? null;

        return new self(
            DocumentLayout::tryFrom(\is_string($layout) ? $layout : '') ?? DocumentLayout::Classic,
            \is_string($accent) ? $accent : self::DEFAULT_ACCENT,
        );
    }

    private static function luminance(string $colour): float
    {
        $channels = array_map(static function (int $value): float {
            $channel = $value / 255;

            return $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        }, self::channels($colour));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /** `$from` moved towards `$to` by `$share` of the way. */
    private static function mix(string $from, string $to, float $share): string
    {
        $target = self::channels($to);

        return '#'.implode('', array_map(
            static fn (int $value, int $index): string => \sprintf('%02x', (int) round($value + ($target[$index] - $value) * $share)),
            self::channels($from),
            [0, 1, 2],
        ));
    }

    /** @return array{int, int, int} */
    private static function channels(string $colour): array
    {
        return [(int) hexdec(substr($colour, 1, 2)), (int) hexdec(substr($colour, 3, 2)), (int) hexdec(substr($colour, 5, 2))];
    }
}
