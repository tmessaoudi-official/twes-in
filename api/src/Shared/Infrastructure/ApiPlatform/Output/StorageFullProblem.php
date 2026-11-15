<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform\Output;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The installation's disk has no room left, the database's or the files': nothing this request did was kept, and the
 * same request goes through once room is made, which only the people looking after the installation can do. 507, the
 * status HTTP gives a server that cannot store what it needs to finish, and a type of its own, which a screen says.
 */
final class StorageFullProblem extends HttpException implements ProblemExceptionInterface
{
    public const string TYPE = '/problems/storage-full';

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(507, "The installation's disk is full: this could not be done, and nothing was saved. Tell whoever runs the installation, then try again.", $previous);
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getTitle(): string
    {
        return 'Storage full';
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
