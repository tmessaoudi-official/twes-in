<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Application;

/**
 * Whether a text the API writes with its forms, « …1 heure…|…2 heures… », can be written for any count its code hands
 * it, read as the mails and the printed documents write it.
 */
interface CountedForms
{
    public function everyCountChooses(string $text, string $language): bool;
}
