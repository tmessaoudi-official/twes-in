<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Http;

use App\Identity\Application\CustomerScreen\CustomerScreenLock;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Opens the customer screen on this company and holds the sign-in on it (docs/SPEC.md § 7, 2026-10-06 19:44). Whoever
 * may read the products the screen shows may open it; a company that is none of theirs answers as a stranger's.
 */
#[AsController]
final readonly class LockCustomerScreenController
{
    public function __construct(private CompanyGuard $guard, private CustomerScreenLock $lock)
    {
    }

    #[Route('/api/companies/{companyId}/customer-screen/lock', name: 'api_customer_screen_lock', methods: ['POST'])]
    public function __invoke(string $companyId): Response
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), ProductPermission::READ);
        $this->lock->lock($this->guard->account()->getId(), $company->getId());

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
