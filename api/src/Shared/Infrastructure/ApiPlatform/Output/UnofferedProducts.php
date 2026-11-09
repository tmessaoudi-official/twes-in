<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform\Output;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * What the words typed in a product selector find that the selector does not offer, so an empty one says why rather
 * than that no product exists. Every selector answers this one shape at its own `…/products/unoffered`, under its own
 * permission, a count left at 0 where it hides nothing of that sort; the screen says each count that is not 0.
 */
final class UnofferedProducts
{
    public const string READ = 'unoffered_products:read';

    /** Products switched off, of the kind the selector offers: reactivated, they would be offered. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public int $deactivated = 0;

    /** Services, on or off, where the selector offers goods alone (an order, the stock). */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public int $services = 0;

    /**
     * Active goods whose stock is not kept, which the stock's selectors leave out: counted among the goods its search
     * reads, as the selector itself reads them, so at most that many.
     */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public int $notStocked = 0;

    /** @param array{deactivated: int, services: int} $unoffered */
    public static function of(array $unoffered, int $notStocked = 0): self
    {
        $answer = new self();
        $answer->deactivated = $unoffered['deactivated'];
        $answer->services = $unoffered['services'];
        $answer->notStocked = $notStocked;

        return $answer;
    }
}
