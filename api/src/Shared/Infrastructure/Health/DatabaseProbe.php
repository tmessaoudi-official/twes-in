<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

/**
 * Answers one question: can the application reach its database right now?
 * Abstracted so the health endpoint's degraded path is testable without a broken database.
 */
interface DatabaseProbe
{
    public function isReachable(): bool;
}
