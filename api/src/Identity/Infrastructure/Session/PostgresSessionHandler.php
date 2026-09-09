<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Session;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

/**
 * Sessions live in the "sessions" table of the application database (migration Version20260909000000 owns
 * the DDL; Doctrine's schema_filter hides the table from diffs). Its own PDO connection, as Symfony's handler
 * expects: session writes happen after the response and must not ride on Doctrine's transaction.
 */
final class PostgresSessionHandler extends PdoSessionHandler
{
    public function __construct(#[Autowire(env: 'DATABASE_URL')] string $databaseUrl)
    {
        parent::__construct($databaseUrl, ['lock_mode' => self::LOCK_ADVISORY]);
    }
}
