<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\InvalidSettingValue;
use App\Settings\Application\SettingLevelRefused;
use App\Settings\Application\UnknownSetting;
use App\Settings\Domain\SettingLevel;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<SettingResource, SettingResource> */
final readonly class ChangeSettingProcessor implements ProcessorInterface
{
    public function __construct(private ChangeSettings $change, private SettingAccess $access)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SettingResource
    {
        $companyId = CompanyPath::identifier($uriVariables, 'companyId');
        // Membership first: a stranger learns nothing from a malformed body.
        $this->access->companyToRead($companyId);
        $level = SettingLevel::tryFrom($data->level) ?? throw new UnprocessableEntityHttpException(\sprintf('level: no level is called %s.', $data->level));
        $company = $this->access->companyToWrite($companyId, $level);
        $settingContext = $this->access->contextToWrite($company, $level, $data->roleId, $data->customerId, $data->customerGroupId);

        try {
            $setting = $this->change->change($settingContext, SettingKey::of($uriVariables), $level, $data->value, $this->access->callerId());
        } catch (UnknownSetting $unknown) {
            throw new NotFoundHttpException($unknown->getMessage(), $unknown);
        } catch (SettingLevelRefused|InvalidSettingValue $refused) {
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        }

        return SettingResource::of($setting, $this->access->writableLevels($setting->definition, $this->access->mayShare($company), $this->access->mayWriteParties($company), $settingContext));
    }
}
