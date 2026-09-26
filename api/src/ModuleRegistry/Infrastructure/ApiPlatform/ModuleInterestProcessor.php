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
use App\ModuleRegistry\Application\ModuleAlreadyAvailable;
use App\ModuleRegistry\Application\ModuleInterests;
use App\ModuleRegistry\Application\UnknownModule;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * « Me prévenir » and its withdrawal, taken by whoever may switch modules: answers the module's row as the list reads it.
 *
 * @implements ProcessorInterface<ModuleResource, ModuleResource>
 */
final readonly class ModuleInterestProcessor implements ProcessorInterface
{
    public function __construct(private ModuleInterests $interests, private ManageModules $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ModuleResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ModulePermission::WRITE);
        $key = $uriVariables['moduleKey'] ?? null;
        $key = \is_string($key) ? $key : '';

        try {
            $interested = $this->interests->set($company, $key, true === $data->interested, $this->guard->account()->getId());
        } catch (UnknownModule $absent) {
            throw new NotFoundHttpException('No such module.', $absent);
        } catch (ModuleAlreadyAvailable $refused) {
            throw new ConflictHttpException($refused->getMessage(), $refused);
        }

        foreach ($this->manage->list($company) as $view) {
            if ($view->manifest->key === $key) {
                return ModuleResource::of($view, $interested);
            }
        }

        throw new \LogicException(\sprintf('The module %s was just asked for and is no longer listed.', $key));
    }
}
