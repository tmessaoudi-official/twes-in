<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Console;

use App\Tenancy\Application\Seed\OperatorPasswordRequired;
use App\Tenancy\Application\Seed\SeedPlatform;
use App\Tenancy\Application\Seed\SeedRequest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** `app:seed`: the console face of Tenancy\Application\Seed\SeedPlatform. The password is an explicit option: no default ever ships in code. */
#[AsCommand(name: 'app:seed', description: 'Create the built-in roles, the platform operator and a first company (idempotent).')]
final class SeedCommand extends Command
{
    public function __construct(private readonly SeedPlatform $seed)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('operator-email', null, InputOption::VALUE_REQUIRED, 'Email of the platform operator', 'operator@twes.local')
            ->addOption('operator-password', null, InputOption::VALUE_REQUIRED, 'Password of the platform operator (required when the user is created)')
            ->addOption('operator-name', null, InputOption::VALUE_REQUIRED, 'Display name of the platform operator', 'Operator')
            ->addOption('company-name', null, InputOption::VALUE_REQUIRED, 'Name of the first company', 'Demo')
            ->addOption('country', null, InputOption::VALUE_REQUIRED, 'ISO 3166-1 alpha-2 country of the first company', 'TN')
            ->addOption('currency', null, InputOption::VALUE_REQUIRED, 'ISO 4217 currency of the first company', 'TND')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Locale of the first company and the operator', 'fr')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Timezone of the first company', 'Africa/Tunis');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $password = $input->getOption('operator-password');

        try {
            $created = $this->seed->seed(new SeedRequest(
                self::option($input, 'operator-email'),
                \is_string($password) ? $password : null,
                self::option($input, 'operator-name'),
                self::option($input, 'company-name'),
                self::option($input, 'country'),
                self::option($input, 'currency'),
                self::option($input, 'locale'),
                self::option($input, 'timezone'),
            ));
        } catch (OperatorPasswordRequired $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $io->success([] === $created ? 'Nothing to do: every seed row already exists.' : 'Created: '.implode(', ', $created).'.');

        return Command::SUCCESS;
    }

    private static function option(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return \is_string($value) ? $value : '';
    }
}
