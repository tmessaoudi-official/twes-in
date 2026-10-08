<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

/** Pictures made for a test, so what a thumbnail must look like is known from how the picture was drawn. */
trait MakesPictures
{
    /**
     * A JPEG of this size, its left half red and its right half blue.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private static function jpeg(int $width, int $height, ?int $orientation = null): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height - 1, (int) imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, intdiv($width, 2) + 1, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 0, 0, 255));
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();

        return null === $orientation ? $bytes : self::withOrientation($bytes, $orientation);
    }

    /**
     * A PNG of this size, fully transparent.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private static function transparentPng(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** A GIF: a picture, but not one of the types a photo is kept in. */
    private static function gif(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagegif($image);

        return (string) ob_get_clean();
    }

    /**
     * A PNG whose header claims this size and holds no pixels: what a decompression bomb looks like before it is
     * decoded. The header's checksum is right, so only the size gives it away.
     */
    private static function pngClaiming(int $width, int $height): string
    {
        $header = 'IHDR'.pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).$header.pack('N', crc32($header)).pack('N', 0).'IEND'.pack('N', crc32('IEND'));
    }

    /** The JPEG with an EXIF segment saying how a phone held it: 6 is turned a quarter clockwise, 8 counter-clockwise. */
    private static function withOrientation(string $jpeg, int $orientation): string
    {
        // Big-endian TIFF with one IFD entry: tag 0x0112 Orientation, type SHORT, count 1, the value, no next IFD.
        $tiff = "MM\x00\x2A".pack('N', 8).pack('n', 1).pack('nnN', 0x0112, 3, 1).pack('nn', $orientation, 0).pack('N', 0);
        $segment = "Exif\x00\x00".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', \strlen($segment) + 2).$segment.substr($jpeg, 2);
    }

    /** @return array{int, int} width and height of what the bytes decode to */
    private static function sizeOf(string $bytes): array
    {
        $size = getimagesizefromstring($bytes);
        if (false === $size) {
            throw new \LogicException('not a picture');
        }

        return [$size[0], $size[1]];
    }
}
