<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Pushes a message to the browsers connected to a channel. Best-effort by contract: it never throws, and a failure
 * is the adapter's to log. What must not be lost is written before this is called (docs/SPEC.md § 7, 2026-09-13).
 */
interface RealtimePublisher
{
    /** @param array<string, mixed> $data */
    public function push(string $channel, array $data): void;
}
