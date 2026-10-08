<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Files\Infrastructure;

use App\Files\Infrastructure\Gd\GdPictures;
use App\Tests\Support\MakesPictures;
use PHPUnit\Framework\TestCase;

/** A picture is read from its bytes and cut on the server, upright, never enlarged, its transparency kept. */
final class GdPicturesTest extends TestCase
{
    use MakesPictures;

    public function testAPictureIsReadForItsTypeAndSize(): void
    {
        $picture = new GdPictures()->inspect(self::jpeg(300, 200));

        self::assertNotNull($picture);
        self::assertSame(['image/jpeg', 300, 200], [$picture->mime, $picture->width, $picture->height]);
    }

    public function testOnlyAJpegAPngOrAWebpIsAPicture(): void
    {
        $pictures = new GdPictures();

        foreach ([
            'a GIF' => self::gif(),
            'a vector file, which can carry a script' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'a PDF' => "%PDF-1.4\n1 0 obj<<>>endobj",
            'bytes that are no picture' => 'not a picture at all',
            'nothing' => '',
        ] as $why => $bytes) {
            self::assertNull($pictures->inspect($bytes), $why);
        }
        self::assertSame('image/png', $pictures->inspect(self::transparentPng(2, 2))?->mime);
    }

    public function testASizeIsReadFromTheHeaderWithoutDecodingThePixels(): void
    {
        $picture = new GdPictures()->inspect(self::pngClaiming(20000, 20000));

        self::assertSame([20000, 20000], [$picture?->width, $picture?->height]);
    }

    public function testAPictureIsFittedWithinItsLongestSideAndKeepsItsShape(): void
    {
        $fitted = new GdPictures()->fitted(self::jpeg(1200, 800), 300);

        self::assertSame('image/webp', new \finfo(\FILEINFO_MIME_TYPE)->buffer($fitted));
        self::assertSame([300, 200], self::sizeOf($fitted));
    }

    public function testASmallPictureIsNeverEnlarged(): void
    {
        self::assertSame([100, 50], self::sizeOf(new GdPictures()->fitted(self::jpeg(100, 50), 300)));
    }

    public function testAPhotoIsTurnedUprightAsThePhoneThatTookItSays(): void
    {
        $pictures = new GdPictures();
        $held = self::jpeg(400, 200, orientation: 6);

        $picture = $pictures->inspect($held);
        self::assertSame([200, 400], [$picture?->width, $picture?->height], 'the size said is the upright one');

        $fitted = $pictures->fitted($held, 100);
        self::assertSame([50, 100], self::sizeOf($fitted));
        // Turned a quarter clockwise, the red left half becomes the top: the top is red, the bottom blue.
        $image = imagecreatefromstring($fitted);
        self::assertNotFalse($image);
        self::assertSame('red', self::colourAt($image, 25, 10));
        self::assertSame('blue', self::colourAt($image, 25, 90));
    }

    public function testATransparentPictureStaysTransparent(): void
    {
        $image = imagecreatefromstring(new GdPictures()->fitted(self::transparentPng(400, 400), 100));

        self::assertNotFalse($image);
        self::assertSame(127, (imagecolorat($image, 50, 50) >> 24) & 0x7F);
    }

    private static function colourAt(\GdImage $image, int $x, int $y): string
    {
        $rgb = imagecolorat($image, $x, $y);
        [$red, $blue] = [($rgb >> 16) & 0xFF, $rgb & 0xFF];

        return $red > 200 && $blue < 60 ? 'red' : ($blue > 200 && $red < 60 ? 'blue' : \sprintf('#%06x', $rgb & 0xFFFFFF));
    }
}
