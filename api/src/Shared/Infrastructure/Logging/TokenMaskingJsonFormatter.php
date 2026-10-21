<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * Production's JSON line with every link's token masked (SecretTokens). Masked once the line is written rather than
 * record by record, because a token also stands where no processor can change it: in an exception's own message
 * (« No route found for "GET /api/invitations/… »), which the formatter reads from the object itself.
 */
final class TokenMaskingJsonFormatter extends JsonFormatter
{
    public function format(LogRecord $record): string
    {
        return SecretTokens::mask(parent::format($record));
    }

    public function formatBatch(array $records): string
    {
        return SecretTokens::mask(parent::formatBatch($records));
    }
}
