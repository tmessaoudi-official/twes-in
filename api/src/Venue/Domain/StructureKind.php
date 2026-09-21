<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Venue\Domain;

/**
 * What a piece of the building is (the approved canvas's Structure board, docs/SPEC.md row 83). The four are the
 * board's own four tools — mur, porte, poteau, quai — and nothing here holds goods.
 *
 * `Dock` is the opening a lorry backs to, cut through the building's envelope. It is NOT the dock BAY the stock
 * palette poses in front of it (`venue.shape.dock.*`), which is a stock location and does hold goods: the plan
 * calls both *quai* and only one of them is ever counted.
 */
enum StructureKind: string
{
    case Wall = 'wall';
    case Door = 'door';
    case Post = 'post';
    case Dock = 'dock';
}
