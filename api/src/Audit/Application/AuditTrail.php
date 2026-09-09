<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Application;

/** The one way a row enters audit_log. Recording is durable on return, whatever happens to the request afterwards. */
interface AuditTrail
{
    public function record(AuditEntry $entry): void;
}
