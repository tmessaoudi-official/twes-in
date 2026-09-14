<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform;

/**
 * The permissions behind every delivery notes endpoint: reading notes, writing drafts, and validating one, which
 * numbers it and fixes what it says, the way issuing an invoice is its own permission.
 */
final class DeliveryNotePermission
{
    public const string READ = 'delivery_note.read';
    public const string WRITE = 'delivery_note.write';
    public const string VALIDATE = 'delivery_note.validate';
}
