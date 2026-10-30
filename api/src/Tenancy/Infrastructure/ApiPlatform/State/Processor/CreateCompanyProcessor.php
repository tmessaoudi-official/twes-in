<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Identity\Infrastructure\StepUp\RecentProof;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Application\Company\CompanyNameTaken;
use App\Tenancy\Application\Company\CreateCompany;
use App\Tenancy\Application\Company\NewCompany;
use App\Tenancy\Application\Company\NoFiscalPreset;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\CompanyResource;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A company created from the platform page. A platform write like those under /api/platform, so it asks the operator
 * for a recent proof of who is at the screen (docs/SPEC.md § 7, the step-up list), after the permission and the body.
 *
 * @implements ProcessorInterface<CompanyResource, CompanyResource>
 */
final readonly class CreateCompanyProcessor implements ProcessorInterface
{
    public function __construct(private CreateCompany $createCompany, private CompanyGuard $guard, private RecentProof $proof)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CompanyResource
    {
        $this->proof->demand($this->guard->account()->getId());

        try {
            $company = $this->createCompany->handle(
                new NewCompany($data->name, $data->countryCode, $data->currency, $data->locale, $data->timezone),
                $this->guard->account()->getId(),
            );
        } catch (CompanyNameTaken $taken) {
            throw new ConflictHttpException($taken->getMessage(), $taken);
        } catch (NoFiscalPreset $unsupported) {
            throw DomainRule::refused('countryCode', $unsupported->getMessage(), 'invalid_value', [], $unsupported);
        }

        return CompanyResource::of($company);
    }
}
