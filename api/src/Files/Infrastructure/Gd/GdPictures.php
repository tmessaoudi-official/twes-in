<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Infrastructure\Gd;

use App\Files\Application\Picture;
use App\Files\Application\Pictures;

/**
 * Pictures through PHP's GD, which the API image builds in. A phone stores a photo as its sensor saw it and writes in
 * its EXIF how it was held; that is read here, so a size and a thumbnail are the photo as a person sees it.
 */
final readonly class GdPictures implements Pictures
{
    private const array TYPES = [\IMAGETYPE_JPEG => 'image/jpeg', \IMAGETYPE_PNG => 'image/png', \IMAGETYPE_WEBP => 'image/webp'];

    /** What a photo thumbnail is shown at: the loss is not seen, the bytes are a fifth of a lossless copy's. */
    private const int QUALITY = 82;

    public function inspect(string $bytes): ?Picture
    {
        if ('' === $bytes) {
            return null;
        }
        $mime = (string) new \finfo(\FILEINFO_MIME_TYPE)->buffer($bytes);
        if (!\in_array($mime, self::TYPES, true)) {
            return null;
        }
        // Bytes that only start like a picture make it warn as well as answer false, and false is the answer used.
        $size = @getimagesizefromstring($bytes);
        if (false === $size || (self::TYPES[$size[2]] ?? null) !== $mime || $size[0] < 1 || $size[1] < 1) {
            return null;
        }
        $sideways = \in_array(self::orientation($bytes, $mime), [5, 6, 7, 8], true);

        return new Picture($mime, $sideways ? $size[1] : $size[0], $sideways ? $size[0] : $size[1]);
    }

    public function fitted(string $bytes, int $longestSide): string
    {
        $picture = $this->inspect($bytes);
        // Warns as well when it cannot decode; inspect() has already said these bytes are a picture.
        $image = null === $picture ? false : @imagecreatefromstring($bytes);
        if (false === $image) {
            throw new \InvalidArgumentException('These bytes are not a picture that can be decoded.');
        }
        imagepalettetotruecolor($image);
        $image = self::upright($image, self::orientation($bytes, $picture->mime));

        [$width, $height] = [imagesx($image), imagesy($image)];
        $scale = min(1, $longestSide / max($width, $height));
        if ($scale < 1) {
            $fitted = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            imagealphablending($fitted, false);
            imagefill($fitted, 0, 0, (int) imagecolorallocatealpha($fitted, 0, 0, 0, 127));
            imagecopyresampled($fitted, $image, 0, 0, 0, 0, imagesx($fitted), imagesy($fitted), $width, $height);
            $image = $fitted;
        }
        imagesavealpha($image, true);

        ob_start();
        imagewebp($image, null, self::QUALITY);

        return (string) ob_get_clean();
    }

    /** How the camera was held, 1 to 8 as EXIF counts it; 1, as stored, when the photo says nothing readable. */
    private static function orientation(string $bytes, string $mime): int
    {
        if ('image/jpeg' !== $mime) {
            return 1;
        }
        $stream = fopen('php://memory', 'r+');
        if (false === $stream) {
            return 1;
        }
        fwrite($stream, $bytes);
        rewind($stream);
        // A malformed EXIF segment warns as well as answering false; such a photo is taken as it is stored.
        $exif = @exif_read_data($stream);
        fclose($stream);
        $orientation = \is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        return \is_int($orientation) && $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /** imagerotate() turns counter-clockwise; a mirrored orientation is a turn and a flip. */
    private static function upright(\GdImage $image, int $orientation): \GdImage
    {
        $turned = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, 270, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };
        if (false === $turned) {
            throw new \RuntimeException('The picture could not be turned upright.');
        }
        match ($orientation) {
            2, 5, 7 => imageflip($turned, \IMG_FLIP_HORIZONTAL),
            4 => imageflip($turned, \IMG_FLIP_VERTICAL),
            default => true,
        };

        return $turned;
    }
}
