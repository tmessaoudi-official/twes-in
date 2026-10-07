<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure\ApiPlatform;

/**
 * Who may read the company's « Journal d'activité » (docs/SPEC.md § 7, 2026-09-26 23:04): the owner and the
 * administrator by default, and any role the company gives it to.
 */
final class AuditPermission
{
    public const string READ = 'audit.read';
}
