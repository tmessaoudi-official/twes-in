<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;
use App\Shared\Domain\Service\ContactRules;

/**
 * How a company reaches the people who run the platform, which the help panel offers every signed-in person under
 * « Nous contacter ». Kept apart from the publisher's contact the legal pages name (`legal.publisher.*`): that one is
 * the registered office's, this one is the line a shop calls when the counter is stuck, often a mobile and its
 * WhatsApp. Each is empty until an operator fills it in, and an empty one is not offered.
 */
final readonly class SupportSettings implements DeclaresSettings
{
    public const string MODULE = 'core';
    public const string PHONE = 'support.phone';
    public const string WHATSAPP = 'support.whatsapp';
    public const string EMAIL = 'support.email';
    /** The monitor's public status page, which the footer of every page links to, signed out too (`/api/health`). */
    public const string STATUS_URL = 'support.status_url';

    // Each also takes the empty value, which is how a channel is taken away.
    /**
     * Every page links to the phone, signed out too: it holds 6 to 15 digits, the most an international number has, so
     * signs alone (`------`) never make a link that calls nothing. Read the same way by the browser, which is handed it.
     */
    private const string PHONE_PATTERN = '/^((?=(?:[^0-9]*[0-9]){6,15}[^0-9]*$)\+?[0-9 ().\-]+)?$/';
    /** WhatsApp opens a chat only from the number in its international form, the country's code first. */
    private const string WHATSAPP_PATTERN = '/^(\+[1-9][0-9 ]{7,20})?$/';
    /** The one rule every e-mail of the application follows. */
    private const string EMAIL_PATTERN = '/^('.ContactRules::EMAIL_PATTERN.')?$/D';
    /** An https address only: the link is opened by people who are not signed in, from every page. */
    private const string STATUS_URL_PATTERN = '#^(https://[^\s/?\#]+\.[^\s/?\#]+(/\S*)?)?$#';

    public function settings(): iterable
    {
        $chain = SettingChain::Platform;
        $platform = [SettingLevel::Platform];

        yield new SettingDefinition(self::PHONE, SettingType::Text, '', $chain, $platform, 'settings.platform.support.phone', self::MODULE, maxLength: 30, pattern: self::PHONE_PATTERN, patternReason: 'invalid_phone_digits');
        yield new SettingDefinition(self::WHATSAPP, SettingType::Text, '', $chain, $platform, 'settings.platform.support.whatsapp', self::MODULE, maxLength: 30, pattern: self::WHATSAPP_PATTERN, patternReason: 'invalid_whatsapp');
        yield new SettingDefinition(self::EMAIL, SettingType::Text, '', $chain, $platform, 'settings.platform.support.email', self::MODULE, maxLength: 254, pattern: self::EMAIL_PATTERN, patternReason: 'invalid_email');
        yield new SettingDefinition(self::STATUS_URL, SettingType::Text, '', $chain, $platform, 'settings.platform.support.status_url', self::MODULE, maxLength: 200, pattern: self::STATUS_URL_PATTERN, patternReason: 'invalid_status_url');
    }
}
