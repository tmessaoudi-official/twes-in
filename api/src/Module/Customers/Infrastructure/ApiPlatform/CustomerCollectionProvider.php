<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Exception\InvalidArgumentException;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Module\Customers\Application\ManageCustomers;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerSearch;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\Uid\Uuid;

/**
 * One page of a company's customers, searched, narrowed and sorted in the database (docs/SPEC.md § 7, lists at scale).
 * The query parameters are declared on the operation, which checks them before this runs.
 *
 * @implements ProviderInterface<CustomerResource>
 */
final readonly class CustomerCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageCustomers $manage, private CompanyGuard $guard, private Pagination $pagination)
    {
    }

    /** @return TraversablePaginator<CustomerResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CustomerPermission::READ);
        $page = $this->pagination->getPage($context);
        $size = $this->pagination->getLimit($operation, $context);
        if ($size < 1) {
            throw new InvalidArgumentException('A page holds at least one row.');
        }

        $found = $this->manage->search($company, $this->search($operation), new PageRequest($page, $size));

        return new TraversablePaginator(
            new \ArrayIterator(array_map(static fn (Customer $customer) => CustomerResource::of($customer), $found->items)),
            $page,
            $size,
            $found->total,
        );
    }

    private function search(Operation $operation): CustomerSearch
    {
        $value = static fn (string $key): mixed => $operation->getParameters()?->get($key)?->getValue();
        $order = [];
        foreach (CustomerSearch::SORTS as $sort) {
            $direction = $value("order[$sort]");
            if ('asc' === $direction || 'desc' === $direction) {
                $order[$sort] = $direction;
            }
        }
        $text = $value('q');
        $kind = $value('kind');
        $group = $value('customerGroupId');
        $active = $value('isActive');

        return new CustomerSearch(
            \is_string($text) ? $text : null,
            \is_string($kind) ? CustomerKind::from($kind) : null,
            \is_string($group) ? Uuid::fromString($group) : null,
            \is_bool($active) ? $active : null,
            $order,
        );
    }
}
