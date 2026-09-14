<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ModuleRegistry\Application\ManageModules;
use App\ModuleRegistry\Application\ModuleDependenciesDisabled;
use App\ModuleRegistry\Application\ModuleRequired;
use App\ModuleRegistry\Application\UnknownModule;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<ModuleResource, ModuleResource> */
final readonly class SwitchModuleProcessor implements ProcessorInterface
{
    public function __construct(private ManageModules $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ModuleResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ModulePermission::WRITE);
        $key = $uriVariables['moduleKey'] ?? null;

        try {
            $view = $this->manage->switch($company, \is_string($key) ? $key : '', true === $data->enabled, $this->guard->account()->getId());
        } catch (UnknownModule $absent) {
            throw new NotFoundHttpException('No such module.', $absent);
        } catch (ModuleDependenciesDisabled|ModuleRequired $refused) {
            throw new ConflictHttpException($refused->getMessage(), $refused);
        }

        return ModuleResource::of($view);
    }
}
