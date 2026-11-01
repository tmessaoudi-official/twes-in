<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform\Output;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Another write met this one in the database and this one gave way: nothing it did was kept, and the same request sent
 * a moment later goes through, or is refused for what it then finds (the number now taken). A type of its own, since
 * the same request answers 409 for other reasons, and a screen says this one by its type.
 */
final class BusyProblem extends ConflictHttpException implements ProblemExceptionInterface
{
    public const string TYPE = '/problems/busy';

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Another change was being saved at the same moment. Nothing was saved: send it again.', $previous, 0, ['Retry-After' => '1']);
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getTitle(): string
    {
        return 'Busy';
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
