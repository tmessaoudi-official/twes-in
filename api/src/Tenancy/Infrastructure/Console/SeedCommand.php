<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Console;

use App\Tenancy\Application\Seed\InvalidOperatorTotpSecret;
use App\Tenancy\Application\Seed\OperatorPasswordRequired;
use App\Tenancy\Application\Seed\SeedPlatform;
use App\Tenancy\Application\Seed\SeedRequest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** `app:seed`: the console face of Tenancy\Application\Seed\SeedPlatform. The password and any authenticator secret are explicit options: no default ever ships in code. */
#[AsCommand(name: 'app:seed', description: 'Create the built-in roles, the platform operator and a first company (idempotent).')]
final class SeedCommand extends Command
{
    public function __construct(
        private readonly SeedPlatform $seed,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('operator-email', null, InputOption::VALUE_REQUIRED, 'Email of the platform operator', 'operator@twes.local')
            ->addOption('operator-password', null, InputOption::VALUE_REQUIRED, 'Password of the platform operator (required when the user is created)')
            // A deployment's way: a password on the command line stays in the shell's history and shows in `ps`.
            ->addOption('operator-password-stdin', null, InputOption::VALUE_NONE, 'Read the operator\'s password from the first line of standard input instead')
            ->addOption('without-company', null, InputOption::VALUE_NONE, 'Make no first company (production: an operator reaches a company only through support access its owner opens)')
            ->addOption('operator-totp-secret', null, InputOption::VALUE_REQUIRED, 'Base32 authenticator secret enrolled for the operator when they have none (development and CI stacks only)')
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
        // A password on the command line stays in the shell's history and shows in `ps`, and a secret everyone can read
        // in this repository would sign anyone in as the operator: both are for development and CI stacks only.
        if ('prod' === $this->environment) {
            foreach (['operator-password', 'operator-totp-secret'] as $option) {
                if (null !== $input->getOption($option)) {
                    $io->error("--$option is refused in production: give the password with --operator-password-stdin, and enrol the operator's authenticator after their first sign-in.");

                    return Command::INVALID;
                }
            }
        }
        $password = $input->getOption('operator-password');
        if (true === $input->getOption('operator-password-stdin')) {
            if (null !== $password) {
                $io->error('Give the operator\'s password once: --operator-password or --operator-password-stdin.');

                return Command::INVALID;
            }
            $password = self::firstLine($input);
        }
        $totpSecret = $input->getOption('operator-totp-secret');

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
                \is_string($totpSecret) ? $totpSecret : null,
                true !== $input->getOption('without-company'),
            ));
        } catch (OperatorPasswordRequired|InvalidOperatorTotpSecret $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $io->success([] === $created ? 'Nothing to do: every seed row already exists.' : 'Created: '.implode(', ', $created).'.');

        return Command::SUCCESS;
    }

    private static function firstLine(InputInterface $input): ?string
    {
        $stream = ($input instanceof StreamableInputInterface ? $input->getStream() : null) ?? \STDIN;
        $line = fgets($stream);

        return false === $line ? null : rtrim($line, "\r\n");
    }

    private static function option(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return \is_string($value) ? $value : '';
    }
}
