<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Infrastructure\Http;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How long « Effacer des données » can be taken back is the operator's (app.erasure.undoable_for), within bounds checked
 * when the container is built: under an hour the worker, which ends due erasures every quarter hour, takes back the
 * undo of an owner who chose the wrong part, and past a week the copy of what the owner asked to be gone, and the
 * pending erasure that refuses the next one, stay longer than an erasure should.
 */
final class ErasureUndoBounds implements CompilerPassInterface
{
    public const string UNDOABLE_FOR = 'app.erasure.undoable_for';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::duration($container, self::UNDOABLE_FOR, 3_600, 7 * 86_400, 'an hour to a week (PT1H to P7D)');
    }
}
