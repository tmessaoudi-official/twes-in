<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/*
 * Every bundle here is one somebody chose, and the list is short on purpose: a bundle registers services and
 * compiler passes into the container, so an unused one is ambient behaviour nobody asked for.
 *
 * `DoctrineMigrationsBundle` is `all` rather than dev-only, deliberately. Migrations run in PRODUCTION -- that is
 * the whole point of them -- and the tenant-owned tables carry RLS statements a migration must issue. Making it
 * dev-only would mean the deploy path could not create the schema it depends on.
 */
return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],

    /*
     * API Platform is the mechanism `CLAUDE.md` § "The Symfony ecosystem is the ONLY vocabulary" MANDATES for
     * the REST surface -- API Resources rather than a `toArray()` per endpoint, and its pagination extension
     * rather than a hand-rolled limit/offset. Registering it here is what makes that rule real instead of a
     * table entry.
     */
    ApiPlatform\Symfony\Bundle\ApiPlatformBundle::class => ['all' => true],
];
