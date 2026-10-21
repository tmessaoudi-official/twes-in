<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\Watch;

use App\Module\Customers\Application\CustomerCheckScopes;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\Repository\CustomerRepository;
use App\Module\Customers\Domain\ValueObject\CustomerCheckReason;
use App\Module\Customers\Domain\ValueObject\CustomerCheckScope;
use App\Module\Customers\Domain\ValueObject\CustomerSearch;
use App\Module\Customers\Infrastructure\ApiPlatform\Security\CustomerPermission;
use App\Module\Customers\Infrastructure\Module\CustomersModule;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Tenancy\Domain\Model\Company;
use App\Watch\Application\DeclaresWatch;
use App\Watch\Application\WatchItem;

/**
 * « À vérifier » on the customers still sold to: marked by a person, or a business of the company's country without a
 * number its preset requires, which an invoice to it would be refused for. The same narrowing as the list's `toCheck`,
 * so the count and the list agree.
 */
final readonly class CustomerCheckWatch implements DeclaresWatch
{
    public const string TO_CHECK = 'customer.to_check';

    public function __construct(private CustomerRepository $customers, private CustomerCheckScopes $checkScopes)
    {
    }

    public function key(): string
    {
        return 'customer_check';
    }

    public function module(): string
    {
        return CustomersModule::KEY;
    }

    public function permission(): string
    {
        return CustomerPermission::READ;
    }

    public function kinds(): array
    {
        return [self::TO_CHECK];
    }

    public function count(string $kind, Company $company, \DateTimeImmutable $today): int
    {
        return $this->read($kind, $company, $this->checkScopes->of($company), new PageRequest(1, 1))->total;
    }

    public function page(string $kind, Company $company, \DateTimeImmutable $today, PageRequest $request): Page
    {
        $scope = $this->checkScopes->of($company);
        $page = $this->read($kind, $company, $scope, $request);

        return new Page(array_map(static function (Customer $customer) use ($scope): WatchItem {
            $mark = $customer->getCheckMark();

            return new WatchItem(self::TO_CHECK, $customer->getId()->toRfc4122(), [
                'customer' => $customer->getProfile()->name,
                'number' => $customer->getNumber(),
                'reasons' => implode(',', array_map(static fn (CustomerCheckReason $reason): string => $reason->value, $customer->checkReasons($scope))),
                'missing' => implode(',', $customer->missingIdentifiers($scope)),
                'marked' => $mark->marked ? 1 : 0,
                'note' => $mark->note ?? '',
            ]);
        }, $page->items), $page->total, $request);
    }

    /** @return Page<Customer> */
    private function read(string $kind, Company $company, CustomerCheckScope $scope, PageRequest $request): Page
    {
        if (self::TO_CHECK !== $kind) {
            throw new \LogicException(\sprintf('The customer check watch has no %s.', $kind));
        }

        return $this->customers->search($company->getId(), new CustomerSearch(active: true, toCheck: true, checkScope: $scope), $request);
    }
}
