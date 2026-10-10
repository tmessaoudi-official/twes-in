<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Application;

/**
 * A file that is not attached, or not put back: empty, too large, of a type not accepted, or one too many for its
 * subject. The reason is a stable code a screen translates, never the English message.
 */
final class AttachmentRefused extends \DomainException
{
    public const string REFUSED = 'file_refused';
    public const string TOO_MANY_FILES = 'too_many_files';

    /** @param array<string, int|string> $params what the reason's text names, such as the most a subject holds */
    public function __construct(string $message, public readonly string $reason = self::REFUSED, public readonly array $params = [])
    {
        parent::__construct($message);
    }
}
