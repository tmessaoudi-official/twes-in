<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Tenancy\Application\Company\ClosePeriod;
use App\Tenancy\Domain\InvalidClosing;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<CompanyClosingResource, CompanyClosingResource> */
final readonly class ClosePeriodProcessor implements ProcessorInterface
{
    public function __construct(private ClosePeriod $close, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CompanyClosingResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CompanyProfileResource::WRITE_PERMISSION);
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $data->closedThrough, new \DateTimeZone('UTC'));
        // A day the calendar does not have rolls over rather than failing: compare it with what was sent.
        if (false === $day || $day->format('Y-m-d') !== $data->closedThrough) {
            throw new UnprocessableEntityHttpException('closedThrough: a day written YYYY-MM-DD.');
        }

        try {
            $this->close->handle($company, $day, $this->guard->account()->getId());
        } catch (InvalidClosing $refused) {
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        }

        return CompanyClosingResource::of($company, true);
    }
}
