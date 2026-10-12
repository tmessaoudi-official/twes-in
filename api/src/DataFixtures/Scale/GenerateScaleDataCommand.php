<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataFixtures\Scale;

use App\DataHealth\Application\RunDataChecks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;

#[When('dev')]
#[When('test')]
#[AsCommand(name: 'app:scale:generate', description: 'Grow a company to a target number of invoices by cloning its invoice graph (a scale or test database only).')]
final class GenerateScaleDataCommand extends Command
{
    public function __construct(private readonly ScaleGenerator $generator, private readonly RunDataChecks $checks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'The company to grow, by name', 'Carthage Conseil')
            ->addOption('invoices', null, InputOption::VALUE_REQUIRED, 'Invoice rows the company should hold (100000, 1000000…)', '100000')
            ->addOption('years', null, InputOption::VALUE_REQUIRED, 'Years the copies are spread over', '10')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'The seed of the dates and the names; the same seed gives the same data', '1')
            ->addOption('chunk-copies', null, InputOption::VALUE_REQUIRED, 'Copies of the base graph written per transaction', '2000')
            ->addOption('max-chunks', null, InputOption::VALUE_REQUIRED, 'Stop after this many chunks; a later run resumes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $max = $input->getOption('max-chunks');

        try {
            $report = $this->generator->generate(
                self::text($input, 'company'),
                self::number($input, 'invoices'),
                self::number($input, 'years'),
                self::number($input, 'seed'),
                self::number($input, 'chunk-copies'),
                null === $max ? null : self::number($input, 'max-chunks'),
                static function (string $line) use ($io): void {
                    $io->writeln($line);
                },
            );
        } catch (\RuntimeException|\InvalidArgumentException $refusal) {
            $io->error($refusal->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('%s: %d copies of %d wanted, %d invoice rows.', $report->stopped ? 'stopped' : 'done', $report->copies, $report->wanted, $report->invoices));
        if ($report->stopped) {
            return Command::SUCCESS;
        }

        // What was generated must hold every invariant a real database holds, or it measures data no use case makes.
        $found = 0;
        foreach ($this->checks->runQuietly() as $run) {
            $io->writeln(\sprintf('data check %s: %d found in %.2f s', $run->check, \count($run->findings), $run->seconds));
            foreach ($run->findings as $finding) {
                $io->writeln('  '.$finding->detail);
            }
            $found += \count($run->findings);
        }
        if ($found > 0) {
            $io->error(\sprintf('The generated data breaks the data checks: %d found.', $found));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private static function text(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return \is_string($value) ? $value : throw new \InvalidArgumentException(\sprintf('--%s takes a value.', $name));
    }

    private static function number(InputInterface $input, string $name): int
    {
        $value = $input->getOption($name);
        $text = \is_int($value) ? (string) $value : $value;
        if (!\is_string($text) || 1 !== preg_match('/^\d+$/', $text)) {
            throw new \InvalidArgumentException(\sprintf('--%s takes a whole number.', $name));
        }

        return (int) $text;
    }
}
