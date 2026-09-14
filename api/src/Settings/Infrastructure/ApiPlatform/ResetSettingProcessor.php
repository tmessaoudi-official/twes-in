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
use App\Settings\Application\SettingLevelRefused;
use App\Settings\Application\UnknownSetting;
use App\Settings\Domain\SettingLevel;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Forgets the value stored at one level, named by the `level` query parameter (and `roleId`, `customerId`,
 * `customerGroupId`, `productId` or `productCategoryId` at those levels):
 * the setting falls back to the level above.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class ResetSettingProcessor implements ProcessorInterface
{
    public function __construct(private ChangeSettings $change, private SettingAccess $access)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $companyId = CompanyPath::identifier($uriVariables, 'companyId');
        $this->access->companyToRead($companyId);
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query : null;
        $rawLevel = $query?->getString('level') ?? '';
        $level = SettingLevel::tryFrom($rawLevel) ?? throw new UnprocessableEntityHttpException(\sprintf('level: no level is called %s.', $rawLevel));
        $company = $this->access->companyToWrite($companyId, $level);
        $settingContext = $this->access->contextToWrite($company, $level, $query?->getString('roleId'), $query?->getString('customerId'), $query?->getString('customerGroupId'), $query?->getString('productId'), $query?->getString('productCategoryId'));

        try {
            $this->change->reset($settingContext, SettingKey::of($uriVariables), $level, $this->access->callerId());
        } catch (UnknownSetting $unknown) {
            throw new NotFoundHttpException($unknown->getMessage(), $unknown);
        } catch (SettingLevelRefused $refused) {
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        }

        return null;
    }
}
