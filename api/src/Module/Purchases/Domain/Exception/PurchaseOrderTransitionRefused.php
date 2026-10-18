<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Domain\Exception;

/** A move the order's status does not allow: revising a sent order, receiving against a closed one, cancelling one that received goods. */
final class PurchaseOrderTransitionRefused extends \DomainException
{
}
