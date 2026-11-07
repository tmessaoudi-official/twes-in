<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Numbering;

/**
 * The establishment has no default series for the document type, so the document cannot be numbered. It names both, so
 * the screen can say which establishment to give a series to, for which document, rather than guess.
 */
final class NoNumberingSeries extends \RuntimeException
{
    public function __construct(string $message, public readonly string $establishment, public readonly string $documentType)
    {
        parent::__construct($message);
    }
}
