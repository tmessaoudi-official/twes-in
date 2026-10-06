<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Scanning\Infrastructure\Module;

use App\ModuleRegistry\Application\DeclaresModule;
use App\ModuleRegistry\Application\ModuleManifest;

/**
 * The scanner (docs/SPEC.md § 7, 2026-10-06 21:02): the camera, a phone lent to a tab, and a hand scanner's burst
 * outside a field, which opens the product a code names. Off, none of them is offered and the pairing endpoints answer
 * 404; a hand scanner still types into a field, since nothing can stop a keyboard. A scan finds products, so it needs
 * them, and it introduces no permission: opening a pairing checks `product.read`.
 */
final readonly class ScanningModule implements DeclaresModule
{
    public const string KEY = 'scanning';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::KEY, 'modules.scanning', ['products']);
    }
}
