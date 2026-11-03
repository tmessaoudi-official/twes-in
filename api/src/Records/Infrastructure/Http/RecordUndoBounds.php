<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Infrastructure\Http;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How long a delete, a merge or an import can be taken back is the operator's (app.records.undoable_for), within bounds
 * checked when the container is built: under an hour the worker, which makes due deletes final every quarter hour,
 * takes back the undo a person reaches for after a phone call, and past thirty days the copies « Récemment supprimés »
 * keeps (a vendor's bank details among them) outlive what a bin is for.
 */
final class RecordUndoBounds implements CompilerPassInterface
{
    public const string UNDOABLE_FOR = 'app.records.undoable_for';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::duration($container, self::UNDOABLE_FOR, 3_600, 30 * 86_400, 'an hour to thirty days (PT1H to P30D)');
    }
}
