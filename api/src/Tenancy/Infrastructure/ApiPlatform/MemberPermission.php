<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

/**
 * Who may read a company's members and change them (docs/SPEC.md § 3 Authorization).
 *
 * These two lived as bare literals in three providers until the permission catalogue was built (§ 7, 2026-09-20
 * 11:30): they are granted to the built-in admin, so a company must be able to grant them to a role of its own, and
 * a catalogue assembled from the permission classes alone would have offered every other permission and silently not
 * these. Naming them here is what puts them in it.
 */
final class MemberPermission
{
    public const string READ = 'user.read';
    public const string WRITE = 'user.write';
}
