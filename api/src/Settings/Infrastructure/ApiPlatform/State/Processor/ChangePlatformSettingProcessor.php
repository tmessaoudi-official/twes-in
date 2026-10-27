<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\InvalidSettingValue;
use App\Settings\Application\SettingCatalog;
use App\Settings\Application\SettingContext;
use App\Settings\Application\SettingLevelRefused;
use App\Settings\Application\UnknownSetting;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Infrastructure\ApiPlatform\Output\SettingRefusals;
use App\Settings\Infrastructure\ApiPlatform\Payload\SettingKey;
use App\Settings\Infrastructure\ApiPlatform\Resource\SettingResource;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Stores a platform setting at the platform level; the operation's security has already required an operator. Only a
 * setting declaring the platform level is reached from here: the platform's own, and a company's whose installation
 * default is the operator's; any other company setting has no platform value this screen may set.
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
        if (true !== $this->catalog->definitionOf($key)?->allows(SettingLevel::Platform)) {
            throw new NotFoundHttpException(\sprintf('No platform setting is declared as %s.', $key));
        }

        try {
            $setting = $this->change->change(new SettingContext(), $key, SettingLevel::Platform, $data->value, $this->callerId());
        } catch (UnknownSetting $unknown) {
            throw new NotFoundHttpException($unknown->getMessage(), $unknown);
        } catch (SettingLevelRefused $refused) {
            throw SettingRefusals::ofLevel($key, $refused);
        } catch (InvalidSettingValue $refused) {
            throw SettingRefusals::of($refused);
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
