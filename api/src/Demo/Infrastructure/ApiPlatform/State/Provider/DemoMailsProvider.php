<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Demo\Domain\Repository\DemoMailRepository;
use App\Demo\Domain\Repository\DemoSandboxRepository;
use App\Demo\Infrastructure\ApiPlatform\Output\DemoMailRecord;
use App\Demo\Infrastructure\ApiPlatform\Resource\DemoMails;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<DemoMails> */
final readonly class DemoMailsProvider implements ProviderInterface
{
    /** Whoever may read the sandbox's company reads its mails: its owner, the only member a sandbox has. */
    private const string PERMISSION = 'company.read';

    public function __construct(
        private CompanyGuard $guard,
        private DemoSandboxRepository $sandboxes,
        private DemoMailRepository $mails,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DemoMails
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), self::PERMISSION);
        $sandbox = $this->sandboxes->ofCompany($company->getId()) ?? throw new NotFoundHttpException('This company is no demo sandbox.');

        return new DemoMails(array_map(DemoMailRecord::of(...), $this->mails->ofSandbox($sandbox->getId())));
    }
}
