<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\ShortSales;

/**
 * What « Sortie sans stock enregistré » answers a sale taking more than the stock recorded: let it go below zero, where
 * « À surveiller » and « Sorties sans stock » show it; ask whoever may to confirm it; or refuse it.
 */
enum ShortSaleRule: string
{
    case Allow = 'allow';
    case Confirm = 'confirm';
    case Refuse = 'refuse';
}
