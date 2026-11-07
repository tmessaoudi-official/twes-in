<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Shared\Domain\Service\WrittenDay;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Application\Closing\ClosePeriod;
use App\Tenancy\Domain\Exception\InvalidClosing;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\CompanyClosingResource;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\CompanyProfileResource;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/** @implements ProcessorInterface<CompanyClosingResource, CompanyClosingResource> */
final readonly class ClosePeriodProcessor implements ProcessorInterface
{
    public function __construct(private ClosePeriod $close, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CompanyClosingResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CompanyProfileResource::WRITE_PERMISSION);
        $day = WrittenDay::parsed((string) $data->closedThrough, zone: new \DateTimeZone('UTC'));
        if (null === $day) {
            throw DomainRule::refused('closedThrough', 'A day written YYYY-MM-DD.', 'invalid_value');
        }

        try {
            $this->close->handle($company, $day, $this->guard->account()->getId());
        } catch (InvalidClosing $refused) {
            throw DomainRule::refused('closedThrough', $refused->getMessage(), $refused->reason, ['day' => $refused->day], $refused);
        }

        return CompanyClosingResource::of($company, true);
    }
}
