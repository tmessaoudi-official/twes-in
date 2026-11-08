<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * The file a plain upload route takes, sent as the multipart part named `file`, or the refusal of it on `file` with the
 * reason a screen words, as the file's own rules are refused: none was sent, it is larger than PHP takes (its own limit,
 * which sits above every route's), or it did not arrive whole. The route takes API Platform's error handling for it.
 */
final class ReceivedFile
{
    public const string PART = 'file';
    public const string NOT_SENT = 'value_required';
    public const string TOO_LARGE = 'file_too_large';
    public const string NOT_RECEIVED = 'file_not_received';
    /** Every reason a file is refused with here, before any route reads it. */
    public const array REASONS = [self::NOT_SENT, self::TOO_LARGE, self::NOT_RECEIVED];

    public static function of(Request $request): UploadedFile
    {
        $file = $request->files->get(self::PART);
        if (!$file instanceof UploadedFile) {
            throw self::refused('Send the file as a multipart part named file.', self::NOT_SENT);
        }
        if ($file->isValid()) {
            return $file;
        }

        throw match ($file->getError()) {
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => self::refused($file->getErrorMessage(), self::TOO_LARGE, ['bytes' => self::phpLimit()]),
            \UPLOAD_ERR_NO_FILE => self::refused($file->getErrorMessage(), self::NOT_SENT),
            default => self::refused($file->getErrorMessage(), self::NOT_RECEIVED),
        };
    }

    /** The most a file holds in bytes as PHP takes it: Symfony says a float only past what an int holds. */
    public static function phpLimit(): int
    {
        $limit = UploadedFile::getMaxFilesize();

        return \is_int($limit) ? $limit : \PHP_INT_MAX;
    }

    /** @param array<string, int> $params */
    private static function refused(string $message, string $reason, array $params = []): ValidationException
    {
        return DomainRule::refused(self::PART, $message, $reason, $params);
    }
}
