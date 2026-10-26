<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Application\Numbering\ManageNumberingSeries;
use App\Tenancy\Application\Numbering\NumberingSeriesNotFound;
use App\Tenancy\Application\Numbering\NumberingSeriesOverlap;
use App\Tenancy\Domain\Exception\InvalidNumbering;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\CompanyProfileResource;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\NumberingSeriesResource;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<NumberingSeriesResource, NumberingSeriesResource> */
final readonly class ReviseNumberingSeriesProcessor implements ProcessorInterface
{
    public function __construct(private ManageNumberingSeries $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): NumberingSeriesResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CompanyProfileResource::WRITE_PERMISSION);

        try {
            $series = $this->manage->revise($company, CompanyPath::identifier($uriVariables, 'seriesId'), $data->changes(), $this->guard->account()->getId(), $data->version);
        } catch (NumberingSeriesNotFound $missing) {
            throw new NotFoundHttpException($missing->getMessage(), $missing);
        } catch (NumberingSeriesOverlap $shared) {
            throw new ConflictHttpException($shared->getMessage(), $shared);
        } catch (InvalidNumbering $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason ?? 'invalid_value', $refused->params, $refused);
        }

        return NumberingSeriesResource::of($series, $this->manage->today()->setTimezone(new \DateTimeZone($company->getTimezone())));
    }
}
