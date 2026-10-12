<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataHealth\Application;

use App\Identity\Domain\UserRepository;
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Tenancy\Domain\CompanyRepository;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

/**
 * Runs every check the contexts declared (`DeclaresDataChecks`), over the whole database, and tells the platform's
 * operators of each check and company where something was found, once per run, so a run every night tells them every
 * night until it is mended. They are told the company and a count, never what the documents say: the detail stays
 * with whoever runs the check on the server.
 */
final readonly class RunDataChecks
{
    public const string NOTIFICATION_FAILED = 'data.check_failed';

    /** @var list<DeclaresDataChecks> */
    private array $declarations;

    /** @param iterable<DeclaresDataChecks> $declarations */
    public function __construct(
        #[AutowireIterator('app.data.checks')] iterable $declarations,
        private UserRepository $users,
        private CompanyRepository $companies,
        private Notifications $notifications,
    ) {
        $this->declarations = iterator_to_array($declarations, false);
    }

    /**
     * Every check's code, in the order they run.
     *
     * @return list<string>
     */
    public function checks(): array
    {
        $checks = [];
        foreach ($this->declarations as $declaration) {
            $checks = [...$checks, ...$declaration->dataChecks()];
        }

        return $checks;
    }

    /**
     * What every check finds, telling nobody.
     *
     * @return list<DataFinding>
     */
    public function findings(): array
    {
        return array_merge([], ...array_map(static fn (CheckRun $run): array => $run->findings, $this->run()));
    }

    /**
     * Runs every check, telling nobody.
     *
     * @return list<CheckRun>
     */
    public function runQuietly(): array
    {
        return $this->run();
    }

    /**
     * Runs every check and tells the operators what was found.
     *
     * @return list<CheckRun>
     */
    public function handle(): array
    {
        $runs = $this->run();
        $this->tellOperators(array_merge([], ...array_map(static fn (CheckRun $run): array => $run->findings, $runs)));

        return $runs;
    }

    /**
     * The translation key of a check's name, the same in the screen texts and the mails: an operator reads the check
     * by its name, the console by its code.
     */
    public static function labelKey(string $check): string
    {
        return 'data_checks.'.str_replace('.', '_', $check);
    }

    /** @return list<CheckRun> */
    private function run(): array
    {
        $runs = [];
        foreach ($this->declarations as $declaration) {
            foreach ($declaration->dataChecks() as $check) {
                $started = hrtime(true);
                $findings = [];
                foreach ($declaration->findings($check) as $finding) {
                    $findings[] = $finding;
                }
                $runs[] = new CheckRun($check, $findings, (hrtime(true) - $started) / 1e9);
            }
        }

        return $runs;
    }

    /** @param list<DataFinding> $findings */
    private function tellOperators(array $findings): void
    {
        /** @var array<string, array{check: string, companyId: ?Uuid, count: int}> $groups */
        $groups = [];
        foreach ($findings as $finding) {
            $key = $finding->check.'|'.($finding->companyId?->toRfc4122() ?? '');
            $groups[$key] ??= ['check' => $finding->check, 'companyId' => $finding->companyId, 'count' => 0];
            ++$groups[$key]['count'];
        }
        if ([] === $groups) {
            return;
        }
        $operators = $this->users->platformOperators();
        foreach ($groups as $group) {
            $company = null === $group['companyId'] ? null : $this->companies->ofId($group['companyId'])?->getName();
            foreach ($operators as $operator) {
                $this->notifications->publish(new Notification(
                    'user:'.$operator->getId()->toRfc4122(),
                    self::NOTIFICATION_FAILED,
                    ['check' => $group['check'], 'label_key' => self::labelKey($group['check']), 'company' => $company ?? '—', 'count' => $group['count']],
                ));
            }
        }
    }
}
