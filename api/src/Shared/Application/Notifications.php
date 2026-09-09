<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Real-time delivery. The wire is Centrifugo and it arrives at G2 with the notification centre that displays
 * it (docs/SPEC.md § 7, 2026-09-09); until then the adapter records what would have been delivered, so the
 * use cases are written once and never revisited for the transport.
 */
interface Notifications
{
    public function publish(Notification $notification): void;
}
