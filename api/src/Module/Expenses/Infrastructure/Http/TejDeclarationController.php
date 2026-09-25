<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\Http;

use App\Module\Expenses\Application\Tej\DeclareTejWithholdings;
use App\Module\Expenses\Application\Tej\TejDeclarationRefused;
use App\Module\Expenses\Infrastructure\ApiPlatform\ExpensePermission;
use App\Module\Expenses\Infrastructure\Tej\TejDeclarationXml;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The month's withholdings as the file the company uploads to Tunisia's TEJ platform (docs/research/
 * tax-data-tunisia.md § 2.2), read with expense.read for the company acting. A plain controller rather than a
 * resource: the answer is a file, or the reason there is none. The module guard applies through this class's
 * namespace. Documented in ExpensesOpenApi.
 */
#[AsController]
final readonly class TejDeclarationController
{
    public function __construct(private DeclareTejWithholdings $declare, private TejDeclarationXml $xml, private CompanyGuard $guard)
    {
    }

    #[Route('/api/companies/{companyId}/withholding-declarations/tej/{year}-{month}', name: 'api_expense_tej_declaration', requirements: ['year' => '20\d{2}', 'month' => '0[1-9]|1[0-2]'], methods: ['GET'])]
    public function __invoke(string $companyId, string $year, string $month): Response
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), ExpensePermission::READ);

        try {
            $declaration = $this->declare->declare($company, (int) $year, (int) $month);
        } catch (TejDeclarationRefused $refused) {
            return new JsonResponse(
                ['code' => $refused->reason, 'params' => (object) $refused->params, 'message' => $refused->getMessage(), 'expenses' => $refused->expenses],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return new Response($this->xml->write($declaration), Response::HTTP_OK, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $declaration->fileName()),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
