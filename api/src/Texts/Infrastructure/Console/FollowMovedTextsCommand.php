<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Infrastructure\Console;

use App\Texts\Application\FollowMovedTexts;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `app:texts:follow-moved`, run at every start after the grants (infra/api/migrate.sh): a screen text this release ships
 * in another file than before takes its company's and the installation's wording with it. Run again, it moves nothing.
 */
#[AsCommand(name: 'app:texts:follow-moved', description: 'Move the wording of each screen text a release moved into another file to that file.')]
final class FollowMovedTextsCommand extends Command
{
    public function __construct(private readonly FollowMovedTexts $follow, private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $moved = $this->follow->follow();
        if ($moved > 0) {
            $this->logger->info('Screen texts moved into another file took their wording with them.', ['wordings' => $moved]);
        }
        new SymfonyStyle($input, $output)->success(\sprintf('%d wording(s) followed their text into its file.', $moved));

        return Command::SUCCESS;
    }
}
