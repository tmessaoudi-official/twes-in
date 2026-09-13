<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Tenancy\Application\Numbering\ManageNumberingSeries;
use App\Tenancy\Application\Numbering\NumberingSeriesNotFound;
use App\Tenancy\Domain\InvalidNumbering;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

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
            $series = $this->manage->revise($company, CompanyPath::identifier($uriVariables, 'seriesId'), $data->changes(), $this->guard->account()->getId());
        } catch (NumberingSeriesNotFound $missing) {
            throw new NotFoundHttpException($missing->getMessage(), $missing);
        } catch (InvalidNumbering $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return NumberingSeriesResource::of($series, $this->manage->today()->setTimezone(new \DateTimeZone($company->getTimezone())));
    }
}
