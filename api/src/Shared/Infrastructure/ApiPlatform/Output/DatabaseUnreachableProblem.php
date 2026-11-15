<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform\Output;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * The database did not answer: stopped for an update, restarting, its server gone. Nothing this request did was kept,
 * and the same request goes through once it answers again, a few seconds later after a restart. 503 with a Retry-After,
 * which a screen says as the server out of reach (`isUnreachable`), and a type of its own for whoever reads more.
 */
final class DatabaseUnreachableProblem extends ServiceUnavailableHttpException implements ProblemExceptionInterface
{
    public const string TYPE = '/problems/database-unreachable';

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(5, 'The database is not answering: nothing was saved. Send it again in a moment.', $previous);
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getTitle(): string
    {
        return 'Database unreachable';
    }

    public function getStatus(): int
    {
        return $this->getStatusCode();
    }

    public function getDetail(): string
    {
        return $this->getMessage();
    }

    public function getInstance(): ?string
    {
        return null;
    }
}
