<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform\Output;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Module\Products\Application\ProductBarcodeTaken;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;

/**
 * A code another product holds, answered 409 on the row's code with the holder's reference as a parameter: the screen
 * names that product from the parameter, and the detail keeps its documented wording
 * (`barcodes.<index>.code: <code> is already a code of <reference>.`).
 */
final class BarcodeConflict
{
    public const string REASON = 'barcode_taken';

    public static function of(ProductBarcodeTaken $taken): ValidationException
    {
        return DomainRule::conflict(
            \sprintf('barcodes.%d.code', $taken->index),
            \sprintf('%s is already a code of %s.', $taken->barcode, $taken->heldBy),
            self::REASON,
            ['reference' => $taken->heldBy, 'code' => $taken->barcode],
            $taken,
        );
    }
}
