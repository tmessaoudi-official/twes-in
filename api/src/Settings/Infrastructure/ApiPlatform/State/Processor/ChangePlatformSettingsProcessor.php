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
use App\Settings\Application\UnknownSetting;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Infrastructure\ApiPlatform\Output\SettingRefusals;
use App\Settings\Infrastructure\ApiPlatform\Resource\SettingChangesResource;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Stores a platform page's save at the platform level; the operation's security has already required an operator. A
 * key declaring no platform level refuses the whole save, as the single key's PUT refuses it.
 *
 * @implements ProcessorInterface<SettingChangesResource, null>
 */
final readonly class ChangePlatformSettingsProcessor implements ProcessorInterface
{
    public function __construct(private ChangeSettings $change, private SettingCatalog $catalog, private Security $security)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $changes = $data->input();
        foreach ($changes as $change) {
            if (true !== $this->catalog->definitionOf($change->key)?->allows(SettingLevel::Platform)) {
                throw new NotFoundHttpException(\sprintf('No platform setting is declared as %s.', $change->key));
            }
        }
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            throw new AccessDeniedException();
        }

        try {
            $this->change->changeAll(new SettingContext(), SettingLevel::Platform, $changes, $account->getId());
        } catch (UnknownSetting $unknown) {
            throw new NotFoundHttpException($unknown->getMessage(), $unknown);
        } catch (InvalidSettingValue $refused) {
            throw SettingRefusals::of($refused);
        }

        return null;
    }
}
