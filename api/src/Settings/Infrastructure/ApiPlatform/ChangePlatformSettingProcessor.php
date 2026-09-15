<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\InvalidSettingValue;
use App\Settings\Application\SettingCatalog;
use App\Settings\Application\SettingContext;
use App\Settings\Application\SettingLevelRefused;
use App\Settings\Application\UnknownSetting;
use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingLevel;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Stores a platform setting at the platform level; the operation's security has already required an operator. Only the
 * platform chain is reached from here: a company's setting has no platform value this screen may set.
 *
 * @implements ProcessorInterface<SettingResource, SettingResource>
 */
final readonly class ChangePlatformSettingProcessor implements ProcessorInterface
{
    public function __construct(private ChangeSettings $change, private SettingCatalog $catalog, private Security $security)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SettingResource
    {
        $key = SettingKey::of($uriVariables);
        if (SettingChain::Platform !== $this->catalog->definitionOf($key)?->chain) {
            throw new NotFoundHttpException(\sprintf('No platform setting is declared as %s.', $key));
        }

        try {
            $setting = $this->change->change(new SettingContext(), $key, SettingLevel::Platform, $data->value, $this->callerId());
        } catch (UnknownSetting $unknown) {
            throw new NotFoundHttpException($unknown->getMessage(), $unknown);
        } catch (SettingLevelRefused|InvalidSettingValue $refused) {
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        }

        return SettingResource::of($setting, [SettingLevel::Platform->value]);
    }

    private function callerId(): Uuid
    {
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            throw new AccessDeniedException();
        }

        return $account->getId();
    }
}
