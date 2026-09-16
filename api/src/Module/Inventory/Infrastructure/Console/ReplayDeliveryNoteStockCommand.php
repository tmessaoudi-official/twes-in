<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Console;

use App\Module\DeliveryNotes\Domain\DeliveryNoteRepository;
use App\Module\DeliveryNotes\Domain\DeliveryNoteStatus;
use App\Module\Inventory\Application\MoveStockForDeliveryNotes;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Moves the stock of one delivery note again, after the notification that its move failed (docs/SPEC.md § 7,
 * 2026-09-16). A person names the note: replaying every note without movements would also move stock for notes
 * validated while the company kept none. Safe to run twice: a note whose stock already moved moves nothing more.
 */
#[AsCommand(name: 'app:stock:replay-delivery-note', description: 'Move the stock of one validated or cancelled delivery note again, when its move failed.')]
final class ReplayDeliveryNoteStockCommand extends Command
{
    public function __construct(
        private readonly DeliveryNoteRepository $notes,
        private readonly StockMovementRepository $movements,
        private readonly MoveStockForDeliveryNotes $move,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('company', InputArgument::REQUIRED, 'Id of the company')
            ->addArgument('delivery-note', InputArgument::REQUIRED, 'Id of the delivery note, as the notification names it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        [$companyId, $noteId] = [self::argument($input, 'company'), self::argument($input, 'delivery-note')];
        if (!Uuid::isValid($companyId) || !Uuid::isValid($noteId)) {
            $io->error('The company and the delivery note are named by their ids.');

            return Command::FAILURE;
        }
        $company = Uuid::fromString($companyId);
        $note = $this->notes->ofIdInCompany(Uuid::fromString($noteId), $company);
        if (null === $note) {
            $io->error('No delivery note of this company has this id.');

            return Command::FAILURE;
        }
        $before = \count($this->movements->ofSource(StockMovement::SOURCE_DELIVERY_NOTE, $note->getId(), $company));

        if (DeliveryNoteStatus::Cancelled === $note->getStatus()) {
            $this->move->cancelled($note->getId(), $company);
        } elseif (DeliveryNoteStatus::Draft !== $note->getStatus()) {
            if ($before > 0) {
                $io->success(\sprintf('The stock of %s already moved: nothing to do.', $note->getNumber()));

                return Command::SUCCESS;
            }
            foreach ($this->move->validated($note->getId(), $company, $note->getEstablishment()->getId(), $note->deliveredQuantities()) as $reason) {
                $io->warning($reason);
            }
        } else {
            $io->error('A draft delivery note moves no stock.');

            return Command::FAILURE;
        }

        $moved = \count($this->movements->ofSource(StockMovement::SOURCE_DELIVERY_NOTE, $note->getId(), $company)) - $before;
        $io->success(0 === $moved
            ? \sprintf('The stock of %s already moved: nothing to do.', $note->getNumber() ?? $note->getId()->toRfc4122())
            : \sprintf('%s: %d movement%s written.', $note->getNumber() ?? $note->getId()->toRfc4122(), $moved, 1 === $moved ? '' : 's'));

        return Command::SUCCESS;
    }

    private static function argument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        return \is_string($value) ? $value : '';
    }
}
