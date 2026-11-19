<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure\ApiPlatform\Security;

/**
 * Who may read the company's « Journal d'activité » (docs/SPEC.md § 7, 2026-09-26 23:04): the owner and the
 * administrator by default, and any role the company gives it to.
 */
final class AuditPermission
{
    public const string READ = 'audit.read';
    /** Reading the address a sign-in came from, a person's whereabouts: its own permission, apart from managing the team. */
    public const string ADDRESS_READ = 'audit.address.read';
}
