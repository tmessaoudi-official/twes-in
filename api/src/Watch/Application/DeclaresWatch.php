<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Watch\Application;

use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module's part of « À surveiller »: the subjects it asks a person to look at, each a kind of condition. The home reads
 * only `count`, so a subject's count is its own statement and never builds the rows; a subject's rows are read one page
 * at a time, when somebody opens it (docs/SPEC.md § 7, the subject pages).
 */
#[AutoconfigureTag('app.watch.declarations')]
interface DeclaresWatch
{
    /** Stable, lowercase: the prefix of every kind it answers (`invoices`, `stock`), and the order of the list. */
    public function key(): string;

    /** The key of the module it belongs to: switched off, its subjects are not shown. */
    public function module(): string;

    /** The permission that reads its subject: a member whose role lacks it is shown none of its subjects. */
    public function permission(): string;

    /**
     * The kinds it answers, most pressing first. Each is a screen of its own, so a kind is one thing to act on: expired
     * lots are not expiring lots.
     *
     * @return list<string>
     */
    public function kinds(): array;

    /** How many rows a kind has on the company's day, without reading them. */
    public function count(string $kind, Company $company, \DateTimeImmutable $today): int;

    /**
     * One page of a kind's rows on the company's day, most pressing first. The order ends in an id: a page is cut by
     * offset, and rows that tie on every other key would otherwise repeat on one page and vanish from the next.
     *
     * @return Page<WatchItem>
     */
    public function page(string $kind, Company $company, \DateTimeImmutable $today, PageRequest $request): Page;
}
