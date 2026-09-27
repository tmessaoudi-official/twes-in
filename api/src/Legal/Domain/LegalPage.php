<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Domain;

/** The legal pages the platform publishes (docs/SPEC.md § 8 row 148), each named by the slug its address carries. */
enum LegalPage: string
{
    case Mentions = 'mentions';
    case Privacy = 'privacy';
    case Cookies = 'cookies';
    case Terms = 'terms';
    case Sales = 'sales';
    case Dpa = 'dpa';
    case Source = 'source';
    case Accessibility = 'accessibility';
    case Security = 'security';
}
