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
 * The screen language in force for a person becomes their account's: their own choice, or after going back to the
 * company's, the one their screens now show where they went back. The setting is already committed, so nothing
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
            $this->keep->chosen($event->userId, \is_string($event->inForce) ? $event->inForce : null);
        } catch (\Throwable $failure) {
            $this->logger->error('The account {user} kept its language after its person chose another: {failure}.', ['user' => $event->userId->toRfc4122(), 'failure' => $failure->getMessage(), 'exception' => $failure]);
        }
    }
}
