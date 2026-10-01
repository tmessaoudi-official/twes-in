<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Customers\Application\CustomerNotFound;
use App\Module\Customers\Infrastructure\ApiPlatform\CustomerPermission;
use App\Module\Invoices\Application\InvalidStatementPeriod;
use App\Module\Invoices\Application\StatementOfAccount;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProviderInterface<CustomerStatementResource> */
final readonly class CustomerStatementProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private StatementOfAccount $statement)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CustomerStatementResource
    {
        $companyId = CompanyPath::identifier($uriVariables, 'companyId');
        // The statement shows a customer's money: it takes the right to see both.
        $this->guard->companyForActing($companyId, CustomerPermission::READ);
        $company = $this->guard->companyForActing($companyId, InvoicePermission::READ);
        $from = self::day($operation, 'from');
        $to = self::day($operation, 'to');

        try {
            return CustomerStatementResource::of($this->statement->handle($company, CompanyPath::identifier($uriVariables, 'customerId'), $from, $to));
        } catch (CustomerNotFound $absent) {
            throw new NotFoundHttpException('No such customer.', $absent);
        } catch (InvalidStatementPeriod $refused) {
            throw new UnprocessableEntityHttpException('to: '.$refused->getMessage(), $refused);
        }
    }

    private static function day(Operation $operation, string $name): ?\DateTimeImmutable
    {
        $value = Paging::value($operation, $name);
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        // createFromFormat rolls 2026-13-40 over into a later date: only a day that reads back as written is one.
        if (false === $day || $day->format('Y-m-d') !== $value) {
            throw new UnprocessableEntityHttpException($name.': A day is written YYYY-MM-DD.');
        }

        return $day;
    }
}
