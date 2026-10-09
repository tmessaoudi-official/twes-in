<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Application;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Every kind of notification the contexts declared, in type order. */
final readonly class NotificationKinds
{
    /** @var array<string, NotificationKind> by type */
    private array $kinds;

    /** @param iterable<DeclaresNotificationKinds> $declarations */
    public function __construct(#[AutowireIterator('app.notification.kinds')] iterable $declarations)
    {
        $kinds = [];
        foreach ($declarations as $declaration) {
            foreach ($declaration->notificationKinds() as $kind) {
                if (isset($kinds[$kind->type])) {
                    throw new \LogicException(\sprintf('The notification kind %s is declared twice.', $kind->type));
                }
                $kinds[$kind->type] = $kind;
            }
        }
        ksort($kinds, \SORT_STRING);
        $this->kinds = $kinds;
    }

    public function has(string $type): bool
    {
        return isset($this->kinds[$type]);
    }

    public function get(string $type): ?NotificationKind
    {
        return $this->kinds[$type] ?? null;
    }

    /**
     * What a member of a company is told, so what they may choose about there: a kind of a module switched on, told to
     * a permission their role grants or to their role.
     *
     * @param \Closure(string): bool $may      whether the member's role grants a permission
     * @param \Closure(string): bool $moduleOn whether the company has a module on
     *
     * @return list<NotificationKind>
     */
    public function ofCompany(string $roleName, \Closure $may, \Closure $moduleOn): array
    {
        return array_values(array_filter($this->kinds, static fn (NotificationKind $kind): bool => NotificationAudience::Company === $kind->audience
            && (null === $kind->module || $moduleOn($kind->module))
            && (null === $kind->permission || $may($kind->permission))
            && (null === $kind->role || $kind->role === $roleName)));
    }

    /** @return list<NotificationKind> what an account is told about itself */
    public function personal(): array
    {
        return array_values(array_filter($this->kinds, static fn (NotificationKind $kind): bool => NotificationAudience::Personal === $kind->audience));
    }

    /** @return list<NotificationKind> what the platform's operators are told */
    public function ofPlatform(): array
    {
        return array_values(array_filter($this->kinds, static fn (NotificationKind $kind): bool => NotificationAudience::Platform === $kind->audience));
    }
}
