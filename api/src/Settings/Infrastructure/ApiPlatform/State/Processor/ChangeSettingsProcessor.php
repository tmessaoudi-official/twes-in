<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\InvalidSettingValue;
use App\Settings\Application\UnknownSetting;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Infrastructure\ApiPlatform\Output\SettingRefusals;
use App\Settings\Infrastructure\ApiPlatform\Resource\SettingChangesResource;
use App\Settings\Infrastructure\ApiPlatform\Security\SettingAccess;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<SettingChangesResource, null> */
final readonly class ChangeSettingsProcessor implements ProcessorInterface
{
    public function __construct(private ChangeSettings $change, private SettingAccess $access)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $companyId = CompanyPath::identifier($uriVariables, 'companyId');
        // Membership first: a stranger learns nothing from a malformed body.
        $this->access->companyToRead($companyId);
        $level = SettingLevel::tryFrom($data->level) ?? throw new UnprocessableEntityHttpException(\sprintf('level: no level is called %s.', $data->level));
        $company = $this->access->companyToWrite($companyId, $level);
        $settingContext = $this->access->contextToWrite($company, $level, $data->roleId, $data->customerId, $data->customerGroupId, $data->productId, $data->productCategoryId, $data->establishmentId);

        try {
            $this->change->changeAll($settingContext, $level, $data->input(), $this->access->callerId());
        } catch (UnknownSetting $unknown) {
            throw new NotFoundHttpException($unknown->getMessage(), $unknown);
        } catch (InvalidSettingValue $refused) {
            throw SettingRefusals::of($refused);
        }

        return null;
    }
}
