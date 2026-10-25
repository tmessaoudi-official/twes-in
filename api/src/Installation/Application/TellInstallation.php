<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Installation\Application;

use App\Identity\Domain\Repository\UserRepository;
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Tenancy\Application\Company\DemoCompanies;
use App\Tenancy\Domain\Repository\CompanyRepository;
use App\Tenancy\Domain\Repository\MembershipRepository;

/**
 * What a shop's installation script (scripts/onprem/twes) tells about the installation it runs: a release out goes to
 * each company's owners, who decide when it is applied, but never to a demo sandbox's, a visitor who decides nothing
 * here; a new network address, which every phone that saved the old one must open instead, goes to those owners and
 * to the operators; an update that failed or was rolled back, a health check that fails and backups that failed or stopped go to
 * the platform's operators, who mend them. The script also mails its own alerts, since the application may be what is
 * down; here they reach the bell once it answers.
 *
 * The line comes from a script on the same machine, but it is still checked whole: each kind takes the parameters it
 * names and no other, each once, each a short plain value, so nothing the bell shows was written by anything else.
 */
final readonly class TellInstallation
{
    public const string UPDATE_AVAILABLE = 'installation.update_available';
    public const string ROLLED_BACK = 'installation.rolled_back';
    public const string UPDATE_FAILED = 'installation.update_failed';
    public const string UPDATE_REFUSED = 'installation.update_refused';
    public const string UNHEALTHY = 'installation.unhealthy';
    public const string BACKUP_FAILED = 'installation.backup_failed';
    public const string BACKUP_STOPPED = 'installation.backup_stopped';
    public const string ADDRESS_MOVED = 'installation.address_moved';

    /** Each kind's parameters, true when required. */
    private const array PARAMETERS = [
        self::UPDATE_AVAILABLE => ['version' => true, 'previous' => true],
        self::ROLLED_BACK => ['version' => true, 'previous' => true, 'label' => true],
        self::UPDATE_FAILED => ['version' => true, 'previous' => true, 'label' => true],
        self::UPDATE_REFUSED => ['version' => true],
        self::UNHEALTHY => ['check' => true],
        self::BACKUP_FAILED => ['step' => true, 'version' => false],
        self::BACKUP_STOPPED => ['step' => true],
        self::ADDRESS_MOVED => ['from' => true, 'to' => true],
    ];

    /** A release tag, a backup label, a step, the names of failing checks or an HTTP code: letters, digits and a few marks. */
    private const string VALUE = '/^[\p{L}\p{N} ._:,()+-]{1,120}$/u';

    public function __construct(
        private UserRepository $users,
        private CompanyRepository $companies,
        private MembershipRepository $memberships,
        private Notifications $notifications,
        private DemoCompanies $demo,
    ) {
    }

    /** @return list<string> every kind a script may tell */
    public static function kinds(): array
    {
        return array_keys(self::PARAMETERS);
    }

    /**
     * @param list<string> $parameters each `name=value`
     *
     * @return int how many people were told
     *
     * @throws InstallationLineRefused
     */
    public function handle(string $kind, array $parameters): int
    {
        $payload = self::payload($kind, $parameters);
        if (self::UPDATE_AVAILABLE === $kind) {
            return $this->tellOwners($kind, $payload);
        }
        $told = self::ADDRESS_MOVED === $kind ? $this->tellOwners($kind, $payload) : 0;
        foreach ($this->users->platformOperators() as $operator) {
            $this->notifications->publish(new Notification('user:'.$operator->getId()->toRfc4122(), $kind, $payload));
            ++$told;
        }

        return $told;
    }

    /** @param array<string, string> $payload */
    private function tellOwners(string $kind, array $payload): int
    {
        $sandboxes = [];
        foreach ($this->demo->all() as $sandbox) {
            $sandboxes[$sandbox->toRfc4122()] = true;
        }
        $companies = [];
        foreach ($this->companies->all() as $company) {
            if (!isset($sandboxes[$company->getId()->toRfc4122()])) {
                $companies[] = $company->getId();
            }
        }
        $told = 0;
        foreach ($this->memberships->ownersOfCompanies($companies) as $membership) {
            $this->notifications->publish(new Notification(
                'user:'.$membership->getUser()->getId()->toRfc4122(),
                $kind,
                $payload,
                $membership->getCompany()->getId()->toRfc4122(),
            ));
            ++$told;
        }

        return $told;
    }

    /**
     * @param list<string> $parameters
     *
     * @return array<string, string>
     */
    private static function payload(string $kind, array $parameters): array
    {
        $allowed = self::PARAMETERS[$kind] ?? throw new InstallationLineRefused(\sprintf('"%s" is not a kind an installation tells; it tells %s.', $kind, implode(', ', self::kinds())));
        $payload = [];
        foreach ($parameters as $parameter) {
            $equals = strpos($parameter, '=');
            $name = false === $equals ? $parameter : substr($parameter, 0, $equals);
            if (false === $equals || !\array_key_exists($name, $allowed)) {
                throw new InstallationLineRefused(\sprintf('%s takes %s, each as name=value, not "%s".', $kind, implode(', ', array_keys($allowed)), $name));
            }
            $value = substr($parameter, $equals + 1);
            if (\array_key_exists($name, $payload)) {
                throw new InstallationLineRefused(\sprintf('%s is given twice.', $name));
            }
            if (1 !== preg_match(self::VALUE, $value)) {
                throw new InstallationLineRefused(\sprintf('%s must be up to 120 letters, digits, spaces and . _ : , ( ) + -.', $name));
            }
            $payload[$name] = $value;
        }
        foreach ($allowed as $name => $required) {
            if ($required && !\array_key_exists($name, $payload)) {
                throw new InstallationLineRefused(\sprintf('%s needs %s.', $kind, $name));
            }
        }

        return $payload;
    }
}
