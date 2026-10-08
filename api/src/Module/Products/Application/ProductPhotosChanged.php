<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

/**
 * An order that does not name exactly the photos in the gallery: someone added or removed one since it was read, and
 * putting the rest in order would place a photo nobody saw.
 */
final class ProductPhotosChanged extends \DomainException
{
    public function __construct()
    {
        parent::__construct('The photos of this product changed since they were read; read them again.');
    }
}
