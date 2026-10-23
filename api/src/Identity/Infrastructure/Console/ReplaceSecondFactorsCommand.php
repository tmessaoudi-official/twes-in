<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Console;

use App\Identity\Application\Mfa\NoAccountHoldsAddress;
use App\Identity\Application\Mfa\ReplacedSecondFactors;
use App\Identity\Application\Mfa\ReplaceSecondFactors;
use App\Identity\Domain\ValueObject\Email;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lost phone, spent recovery codes: the installation's owner replaces the account's second factors from the server
 * (docs/RUNBOOK.md « Replace a lost authenticator »). Whoever can run this already holds the machine, so it opens no
 * way in that was not there; it asks first, as nothing undoes it.
 */
#[AsCommand(name: 'app:mfa:replace', description: 'Remove an account\'s authenticator and recovery codes (and its passkeys with --with-passkeys) so it sets a new factor up at its next sign-in.')]
final class ReplaceSecondFactorsCommand extends Command
{
    public function __construct(private readonly ReplaceSecondFactors $replace)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The account\'s e-mail address')
            ->addOption('with-passkeys', null, InputOption::VALUE_NONE, 'Remove its passkeys too, as one may live on the lost device')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace without asking, for a script');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $address = $input->getArgument('email');
        try {
            $email = Email::fromString(\is_string($address) ? $address : '');
        } catch (\InvalidArgumentException) {
            $io->error('Give the account\'s e-mail address.');

            return Command::INVALID;
        }

        $withPasskeys = true === $input->getOption('with-passkeys');
        if (true !== $input->getOption('force')) {
            if (!$input->isInteractive()) {
                $io->error('Nothing undoes a replacement: run it from a terminal, or add --force to a script.');

                return Command::INVALID;
            }
            $what = $withPasskeys ? 'authenticator, recovery codes and passkeys' : 'authenticator and recovery codes';
            if (!$io->confirm(\sprintf('Remove the %s of %s and end every one of its sessions?', $what, $email->value), false)) {
                $io->warning('Nothing was changed.');

                return Command::FAILURE;
            }
        }

        try {
            $replaced = $this->replace->handle($email, $withPasskeys);
        } catch (NoAccountHoldsAddress) {
            $io->error(\sprintf('No account has the address %s. Nothing was changed.', $email->value));

            return Command::FAILURE;
        }

        if ($replaced->nothing()) {
            $io->success(\sprintf('%s has no second factor to replace: it sets one up at its next sign-in if it must.', $email->value));

            return Command::SUCCESS;
        }

        $io->success(\sprintf(
            'Removed the %s of %s and ended its sessions. At its next sign-in, with its password, it sets up a new factor.',
            self::listOf($replaced),
            $email->value,
        ));

        return Command::SUCCESS;
    }

    private static function listOf(ReplacedSecondFactors $replaced): string
    {
        $parts = array_values(array_filter([
            $replaced->authenticator ? 'authenticator app' : null,
            $replaced->recoveryCodes > 0 ? \sprintf('%d recovery code%s', $replaced->recoveryCodes, 1 === $replaced->recoveryCodes ? '' : 's') : null,
            $replaced->passkeys > 0 ? \sprintf('%d passkey%s', $replaced->passkeys, 1 === $replaced->passkeys ? '' : 's') : null,
        ]));
        $last = array_pop($parts);

        return [] === $parts ? (string) $last : implode(', ', $parts).' and '.$last;
    }
}
