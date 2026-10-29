<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Application;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;
use App\Shared\Domain\Service\ContactRules;

/**
 * Who publishes and hosts this platform, which the legal pages name (docs/SPEC.md § 7, 2026-09-26 08:52: a self-hosted
 * install is its own publisher). Platform settings, which operators alone change; `LegalIdentity` reads them. Kept
 * apart from the reader, which depends on the catalogue this declaration feeds.
 */
final readonly class LegalSettings implements DeclaresSettings
{
    public const string MODULE = 'core';
    public const string PREFIX = 'legal.';

    /** The placeholders a text may name, its setting's key without `legal.`, in the order the operator's form lists them. */
    public const array PLACEHOLDERS = [
        'publisher.name',
        'publisher.address',
        'publisher.registration',
        'publisher.email',
        'publisher.phone',
        'publisher.director',
        'host.name',
        'host.address',
        'host.phone',
        'host.country',
        'privacy.email',
        'security.email',
        'commercial.email',
        'mail.provider',
        'backup.provider',
        'monitor.provider',
        'source.url',
        'jurisdiction',
    ];

    public function settings(): iterable
    {
        yield new SettingDefinition(self::PREFIX.'publisher.name', SettingType::Text, '', SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.legal.publisher.name', self::MODULE);
        yield new SettingDefinition(self::PREFIX.'publisher.address', SettingType::Text, '', SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.legal.publisher.address', self::MODULE);
        yield new SettingDefinition(self::PREFIX.'publisher.registration', SettingType::Text, '', SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.legal.publisher.registration', self::MODULE);
        yield new SettingDefinition(self::PREFIX.'publisher.email', SettingType::Text, '', SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.legal.publisher.email', self::MODULE, maxLength: ContactRules::EMAIL_MAX, pattern: ContactRules::EMAIL_OR_EMPTY, patternReason: 'invalid_email');
        yield new SettingDefinition(self::PREFIX.'publisher.phone', SettingType::Text, '', SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.legal.publisher.phone', self::MODULE, phone: true);
        yield new SettingDefinition(self::PREFIX.'publisher.director', SettingType::Text, '', SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.legal.publisher.director', self::MODULE);
        yield new SettingDefinition(self::PREFIX.'host.name', SettingType::Text, '', SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.legal.host.name', self::MODULE);
        yield new SettingDefinition(self::PREFIX.'host.address', SettingType::Text, '', SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.legal.host.address', self::MODULE);
        yield new SettingDefinition(self::PREFIX.'host.phone', SettingType::Text, '', SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.legal.host.phone', self::MODULE, phone: true);
        yield new SettingDefinition(self::PREFIX.'host.country', SettingType::Text, '', SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.legal.host.country', self::MODULE);
        yield new SettingDefinition(self::PREFIX.'privacy.email', SettingType::Text, '', SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.legal.privacy.email', self::MODULE, maxLength: ContactRules::EMAIL_MAX, pattern: ContactRules::EMAIL_OR_EMPTY, patternReason: 'invalid_email');
        yield new SettingDefinition(self::PREFIX.'security.email', SettingType::Text, '', SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.legal.security.email', self::MODULE, maxLength: ContactRules::EMAIL_MAX, pattern: ContactRules::EMAIL_OR_EMPTY, patternReason: 'invalid_email');
        yield new SettingDefinition(self::PREFIX.'commercial.email', SettingType::Text, '', SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.legal.commercial.email', self::MODULE, maxLength: ContactRules::EMAIL_MAX, pattern: ContactRules::EMAIL_OR_EMPTY, patternReason: 'invalid_email');
        yield new SettingDefinition(self::PREFIX.'mail.provider', SettingType::Text, '', SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.legal.mail.provider', self::MODULE);
        yield new SettingDefinition(self::PREFIX.'backup.provider', SettingType::Text, '', SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.legal.backup.provider', self::MODULE);
        yield new SettingDefinition(self::PREFIX.'monitor.provider', SettingType::Text, '', SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.legal.monitor.provider', self::MODULE);
        yield new SettingDefinition(self::PREFIX.'source.url', SettingType::Text, '', SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.legal.source.url', self::MODULE);
        yield new SettingDefinition(self::PREFIX.'jurisdiction', SettingType::Text, '', SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.legal.jurisdiction', self::MODULE);
    }
}
