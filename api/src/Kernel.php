<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * The Symfony application kernel.
 *
 * **Hand-written, because `symfony/flex` is deliberately not a dependency.** Flex generates this file and the
 * `config/` tree from recipes, and it is a Composer *plugin* — plugins are disabled in this container for
 * safety, and a generated file nobody chose is a file nobody can explain. Every entry under `config/` here
 * exists because something needs it, which is the same argument § Architecture makes for XML mapping over
 * attributes: the wiring is visible rather than implied.
 *
 * `MicroKernelTrait` gives `configureContainer()` and `configureRoutes()` and the conventional
 * `config/{packages}/*.yaml` loading, so this class stays the two hooks and nothing else.
 *
 * **Note what is NOT here: the kernel knows nothing about tenancy.** Binding a tenant is a per-REQUEST and
 * per-CONNECTION concern, not a boot-time one, and `PostgresRowLevelSecurityIsolation` documents the window as
 * one connection lifetime. Wiring it into the kernel would make it a global, which is exactly the ambient
 * access § Architecture forbids.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
