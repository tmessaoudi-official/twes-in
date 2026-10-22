<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Console;

use App\Identity\Application\Mfa\ResealSecondFactors;
use App\Identity\Application\Mfa\SecondFactorsUnreadable;
use App\Identity\Infrastructure\Mfa\SodiumSecretCipher;
use App\Shared\Domain\Service\TypedText;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The second half of rotating APP_MFA_KEY (docs/RUNBOOK.md): run under the NEW key, with the api and the worker
 * stopped, since a running one keeps opening secrets with the key it started with. The previous key is the first line
 * of standard input, so it is in no shell history and no process list.
 */
#[AsCommand(name: 'app:keys:rotate-mfa', description: 'Reseal every authenticator secret under the current APP_MFA_KEY; the previous key on standard input.')]
final class RotateMfaKeyCommand extends Command
{
    public function __construct(private readonly ResealSecondFactors $reseal)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $stream = ($input instanceof StreamableInputInterface ? $input->getStream() : null) ?? \STDIN;
        $line = fgets($stream);
        try {
            $previous = new SodiumSecretCipher(false === $line ? '' : TypedText::trimmed($line));
        } catch (\InvalidArgumentException) {
            $io->error('Give the previous APP_MFA_KEY, base64 encoded, as the first line of standard input.');

            return Command::INVALID;
        }

        try {
            $resealed = $this->reseal->from($previous);
        } catch (SecondFactorsUnreadable $unreadable) {
            $io->error($unreadable->getMessage().' Nothing was resealed: find the key they were sealed under first.');

            return Command::FAILURE;
        }

        if (0 === $resealed) {
            $io->success('Nothing to reseal: every authenticator secret already opens with the current key.');
        } else {
            $io->success(\sprintf('Resealed %d authenticator secret%s under the current key.', $resealed, 1 === $resealed ? '' : 's'));
        }

        return Command::SUCCESS;
    }
}
