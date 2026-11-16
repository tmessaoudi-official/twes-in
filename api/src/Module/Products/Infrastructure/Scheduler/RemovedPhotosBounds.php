<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Scheduler;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How long a removed product photo is kept before it and its files go (app.products.removed_photos_kept_for), checked when the
 * container is built: null, the shipped default, keeps it as long as its product; otherwise never under a day, so a
 * removal stays undoable at least as long as any other delete, and at most ten years.
 */
final class RemovedPhotosBounds implements CompilerPassInterface
{
    public const string KEPT_FOR = 'app.products.removed_photos_kept_for';

    public function process(ContainerBuilder $container): void
    {
        if (null !== self::asWritten($container->getParameter(self::KEPT_FOR))) {
            ParameterBounds::duration($container, self::KEPT_FOR, 86_400, 3_650 * 86_400, 'a day to ten years (P1D to P3650D), or null to keep them');
        }
    }

    /** Read as what services.yaml may hold, whatever the compiled container's type says: null among them. */
    private static function asWritten(mixed $value): mixed
    {
        return $value;
    }
}
