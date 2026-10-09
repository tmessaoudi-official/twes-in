<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * How a printed document looks: one of the built-in layouts, the company's accent colour and its logo's printed size.
 * The colours printed beside the accent are derived from it here, so that no accent a company picks prints text that
 * cannot be read: text on a band of the accent, and the accent as text on paper, keep WCAG's 4.5:1. The font, the paper
 * and the watermark are not part of a design.
 */
final readonly class DocumentDesign
{
    /** The ink documents printed in before they had an accent, which the classic layout keeps by default. */
    public const string DEFAULT_ACCENT = '#1f2328';

    /** The room a logo took before its size was a setting, 180 × 64 CSS pixels, which an older document keeps. */
    public const int DEFAULT_LOGO_WIDTH_MM = 48;
    public const int DEFAULT_LOGO_HEIGHT_MM = 17;

    /** From a mark still legible to half an A4 page across, and to a quarter of its height. */
    public const int LOGO_WIDTH_MM_MIN = 10;
    public const int LOGO_WIDTH_MM_MAX = 105;
    public const int LOGO_HEIGHT_MM_MIN = 5;
    public const int LOGO_HEIGHT_MM_MAX = 70;

    private const string PAPER = '#ffffff';
    private const float READABLE = 4.5;

    /** The accent, lower case. */
    public string $accent;

    /**
     * @param int  $logoWidthMm          the logo's printed width, in millimetres
     * @param int  $logoHeightMm         its printed height
     * @param bool $logoKeepsProportions whether the logo fits that size in its own proportions, or is stretched to it
     */
    public function __construct(
        public DocumentLayout $layout = DocumentLayout::Classic,
        string $accent = self::DEFAULT_ACCENT,
        public int $logoWidthMm = self::DEFAULT_LOGO_WIDTH_MM,
        public int $logoHeightMm = self::DEFAULT_LOGO_HEIGHT_MM,
        public bool $logoKeepsProportions = true,
    ) {
        // Written into the document's stylesheet: anything but a colour is refused, never escaped.
        if (1 !== preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            throw new \InvalidArgumentException(\sprintf('A document accent is a colour written #rrggbb, not "%s".', $accent));
        }
        $this->accent = strtolower($accent);
        if ($logoWidthMm < self::LOGO_WIDTH_MM_MIN || $logoWidthMm > self::LOGO_WIDTH_MM_MAX) {
            throw new \InvalidArgumentException(\sprintf('A logo is %d to %d mm wide, not %d.', self::LOGO_WIDTH_MM_MIN, self::LOGO_WIDTH_MM_MAX, $logoWidthMm));
        }
        if ($logoHeightMm < self::LOGO_HEIGHT_MM_MIN || $logoHeightMm > self::LOGO_HEIGHT_MM_MAX) {
            throw new \InvalidArgumentException(\sprintf('A logo is %d to %d mm high, not %d.', self::LOGO_HEIGHT_MM_MIN, self::LOGO_HEIGHT_MM_MAX, $logoHeightMm));
        }
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

    /** @return array{layout: string, accent: string, logoWidthMm: int, logoHeightMm: int, logoKeepsProportions: bool} */
    public function toArray(): array
    {
        return ['layout' => $this->layout->value, 'accent' => $this->accent, 'logoWidthMm' => $this->logoWidthMm, 'logoHeightMm' => $this->logoHeightMm, 'logoKeepsProportions' => $this->logoKeepsProportions];
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
        $width = $data['logoWidthMm'] ?? null;
        $height = $data['logoHeightMm'] ?? null;
        $keeps = $data['logoKeepsProportions'] ?? null;

        return new self(
            DocumentLayout::tryFrom(\is_string($layout) ? $layout : '') ?? DocumentLayout::Classic,
            \is_string($accent) ? $accent : self::DEFAULT_ACCENT,
            \is_int($width) ? $width : self::DEFAULT_LOGO_WIDTH_MM,
            \is_int($height) ? $height : self::DEFAULT_LOGO_HEIGHT_MM,
            !\is_bool($keeps) || $keeps,
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
