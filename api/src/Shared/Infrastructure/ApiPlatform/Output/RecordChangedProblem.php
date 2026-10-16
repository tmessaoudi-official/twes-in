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
 * A save of a version someone else saved since, as a problem with a type of its own: the same request answers 409 for
 * other reasons (a number another record has), and a screen reads the type to merge what changed instead of saying
 * the number is taken.
 */
final class RecordChangedProblem extends ConflictHttpException implements ProblemExceptionInterface
{
    public const string TYPE = '/problems/record-changed';

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('This record was saved by someone else since it was opened.', $previous);
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getTitle(): string
    {
        return 'Record changed';
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
