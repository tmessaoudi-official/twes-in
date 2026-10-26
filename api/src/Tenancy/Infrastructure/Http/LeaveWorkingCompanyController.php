<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Http;

use App\Tenancy\Application\Session\SwitchWorkingCompany;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** « Quitter »: the session leaves the company it works in, as an operator does once done reading one for support. */
#[AsController]
final readonly class LeaveWorkingCompanyController
{
    public function __construct(private SwitchWorkingCompany $switch)
    {
    }

    #[Route('/api/me/company', name: 'api_me_company_leave', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(): Response
    {
        $this->switch->leave();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
