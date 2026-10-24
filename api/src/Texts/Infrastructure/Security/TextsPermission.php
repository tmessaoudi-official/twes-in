<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Infrastructure\Security;

/**
 * Every member reads the company's texts, which its screens show; wording them is the owner's (a custom role may be
 * given it); the installation's are an operator's.
 */
final class TextsPermission
{
    public const string READ = 'company.read';
    public const string WRITE = 'company.texts';
    public const string PLATFORM = 'platform.texts.manage';
}
