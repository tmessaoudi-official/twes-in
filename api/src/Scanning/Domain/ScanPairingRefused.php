<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Scanning\Domain;

/**
 * Why a phone was turned away: `claimed` (the link was already used), `expired` (it was not claimed in time), `ended`
 * (the computer's tab closed, signed out or stopped answering), `key` (not the key this pairing gave), `unknown`.
 */
final class ScanPairingRefused extends \DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("scan pairing refused: $reason");
    }
}
