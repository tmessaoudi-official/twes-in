<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Settings\Application\SettingValuesInUse;
use App\Settings\Application\UnknownSetting;
use App\Settings\Infrastructure\ApiPlatform\Resource\SettingValuesInUseResource;
use App\Settings\Infrastructure\ApiPlatform\Security\SettingAccess;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<SettingValuesInUseResource> */
final readonly class SettingValuesInUseProvider implements ProviderInterface
{
    public function __construct(private SettingValuesInUse $inUse, private SettingAccess $access)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SettingValuesInUseResource
    {
        $company = $this->access->companyToRead(CompanyPath::identifier($uriVariables, 'companyId'));
        $key = $uriVariables['key'] ?? null;
        if (!\is_string($key)) {
            throw new NotFoundHttpException('No such setting.');
        }
        $resource = new SettingValuesInUseResource();
        $resource->key = $key;
        try {
            $resource->company = $this->inUse->ofCompany($company, $key);
            // What customers hold is theirs to know who may read customers, as each one's settings are.
            $resource->parties = $this->access->mayReadParties($company) ? $this->inUse->ofParties($company, $key) : null;
        } catch (UnknownSetting) {
            throw new NotFoundHttpException('No such setting.');
        }

        return $resource;
    }
}
