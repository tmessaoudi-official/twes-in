<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Settings;

use App\Identity\Application\Account\KeepAccountLanguage;
use App\Settings\Application\PresentationSettings;
use App\Settings\Domain\Event\PersonalSettingChanged;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The person's own choice of screen language becomes their account's. The setting is already committed, so nothing
 * here throws: an account left on its previous language is logged, and the next choice puts it right.
 */
#[AsEventListener(event: PersonalSettingChanged::class, method: 'onChanged')]
final readonly class KeepAccountLanguageOnSettings
{
    public function __construct(private KeepAccountLanguage $keep, private LoggerInterface $logger)
    {
    }

    public function onChanged(PersonalSettingChanged $event): void
    {
        if (PresentationSettings::LANGUAGE !== $event->key) {
            return;
        }
        try {
            $this->keep->chosen($event->userId, \is_string($event->value) ? $event->value : null);
        } catch (\Throwable $failure) {
            $this->logger->error('The account {user} kept its language after its person chose another: {failure}.', ['user' => $event->userId->toRfc4122(), 'failure' => $failure->getMessage(), 'exception' => $failure]);
        }
    }
}
