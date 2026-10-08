<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/** Every photo of a gallery, first to last. */
final class ProductPhotoOrder
{
    /** @var list<string> */
    #[Assert\Type('list')]
    #[Assert\All([new Assert\Type('string'), new Assert\Uuid()])]
    public array $photoIds = [];

    /** @return list<Uuid> */
    public function ids(): array
    {
        return array_map(static fn (string $id): Uuid => Uuid::fromString($id), $this->photoIds);
    }
}
