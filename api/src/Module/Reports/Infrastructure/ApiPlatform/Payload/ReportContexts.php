<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Infrastructure\ApiPlatform\Payload;

use App\Module\Reports\Application\ReportContext;
use App\Tenancy\Domain\Repository\EstablishmentRepository;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Who reads a report and where: the company in the path, read with company.read (each report then asks its own
 * permission), today in the company's calendar, and the establishment asked, which must be one of the company's.
 */
final readonly class ReportContexts
{
    /** Anyone who may read the company may ask what reports there are; each report then asks its subject's permission. */
    public const string PERMISSION = 'company.read';

    public function __construct(private CompanyGuard $guard, private ClockInterface $clock, private EstablishmentRepository $establishments)
    {
    }

    /** @param array<string, mixed> $uriVariables */
    public function of(array $uriVariables, ?Uuid $establishment): ReportContext
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), self::PERMISSION);
        if (null !== $establishment && null === $this->establishments->ofIdInCompany($establishment, $company->getId())) {
            throw new UnprocessableEntityHttpException('establishment: the company has no such establishment.');
        }
        $today = new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($company->getTimezone()))->format('Y-m-d'));

        return new ReportContext($company, $today, fn (string $permission): bool => $this->guard->may($company, $permission), $establishment?->toRfc4122());
    }
}
