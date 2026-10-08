<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

/** The product has no photo with this id, or no longer shows it. */
final class ProductPhotoNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No such photo of this product.');
    }
}
