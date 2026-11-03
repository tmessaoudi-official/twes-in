<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\Watch;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The hours a draft delivery note with lines waits before « À surveiller » names it is the operator's
 * (app.watch.drafts_to_validate_after_hours: DeliveryNoteWatch), within bounds checked when the container is built: none
 * names every note still being written, past three days a customer waiting for goods since before the weekend is never
 * named.
 */
final class DraftWatchBounds implements CompilerPassInterface
{
    public const string AFTER_HOURS = 'app.watch.drafts_to_validate_after_hours';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::whole($container, self::AFTER_HOURS, 1, 72);
    }
}
