<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Files\Application\AttachmentRefused;
use App\Files\Application\Attachments;
use App\Files\Application\StoredFileCorrupted;
use App\Files\Application\StoredFileMissing;
use App\Files\Domain\Attachment;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementRepository;
use App\Shared\Application\LiveChange;
use App\Shared\Application\LiveChanges;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The files a loss keeps: the photo of what broke, the complaint filed for a theft, the certificate of a destruction.
 * They are what shows a loss happened as it is written, so only a loss takes them, and every file attached or taken
 * off is an audit row of that loss, naming the attachment and never its name or bytes.
 */
final readonly class KeepLossAttachments
{
    public const string ENTITY_TYPE = 'stock_movement';
    public const string ATTACHMENT_ADDED = 'stock_movement.attachment_added';
    public const string ATTACHMENT_REMOVED = 'stock_movement.attachment_removed';

    public function __construct(
        private StockMovementRepository $movements,
        private Attachments $attachments,
        private Transactions $transactions,
        private AuditTrail $audit,
        private LiveChanges $liveChanges,
    ) {
    }

    /**
     * @return list<Attachment>
     *
     * @throws StockMovementNotFound
     */
    public function attachments(Company $company, Uuid $movementId): array
    {
        return $this->attachments->of($company, self::ENTITY_TYPE, $this->loss($company, $movementId)->getId());
    }

    /**
     * How many files each loss of a page carries, read once for the page; a movement that is not a loss has none to
     * count and is left out.
     *
     * @param list<StockMovement> $movements
     *
     * @return array<string, int> by loss id (RFC 4122)
     */
    public function attachmentCounts(Company $company, array $movements): array
    {
        $losses = array_values(array_filter($movements, static fn (StockMovement $movement): bool => StockMovement::SOURCE_LOSS === $movement->getSourceType()));
        if ([] === $losses) {
            return [];
        }
        $counted = $this->attachments->countsOf($company, self::ENTITY_TYPE, array_map(static fn (StockMovement $loss): Uuid => $loss->getId(), $losses));
        $counts = [];
        foreach ($losses as $loss) {
            $counts[$loss->getId()->toRfc4122()] = $counted[$loss->getId()->toRfc4122()] ?? 0;
        }

        return $counts;
    }

    /**
     * @throws StockMovementNotFound
     * @throws AttachmentRefused
     */
    public function attach(Company $company, Uuid $movementId, string $name, string $contents, ?Uuid $actorUserId): Attachment
    {
        return $this->transactions->run(function () use ($company, $movementId, $name, $contents, $actorUserId): Attachment {
            $loss = $this->loss($company, $movementId);
            $attachment = $this->attachments->attach($company, self::ENTITY_TYPE, $loss->getId(), $name, $contents, $actorUserId);
            $this->recorded($company, $loss, self::ATTACHMENT_ADDED, $attachment->getId(), $actorUserId);

            return $attachment;
        });
    }

    /**
     * @return array{Attachment, string} the attachment and its bytes
     *
     * @throws StockMovementNotFound
     * @throws StockLossAttachmentNotFound
     * @throws StoredFileMissing
     * @throws StoredFileCorrupted
     */
    public function attachmentContents(Company $company, Uuid $movementId, Uuid $attachmentId): array
    {
        $attachment = $this->attachment($company, $this->loss($company, $movementId), $attachmentId);

        return [$attachment, $this->attachments->contents($attachment)];
    }

    /**
     * A file attached by mistake comes off; the loss itself stays as it was written.
     *
     * @throws StockMovementNotFound
     * @throws StockLossAttachmentNotFound
     */
    public function detach(Company $company, Uuid $movementId, Uuid $attachmentId, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $movementId, $attachmentId, $actorUserId): void {
            $loss = $this->loss($company, $movementId);
            $this->attachments->detach($this->attachment($company, $loss, $attachmentId));
            $this->recorded($company, $loss, self::ATTACHMENT_REMOVED, $attachmentId, $actorUserId);
        });
    }

    /** @throws StockMovementNotFound */
    private function loss(Company $company, Uuid $movementId): StockMovement
    {
        $movement = $this->movements->ofIdInCompany($movementId, $company->getId());
        if (null === $movement || StockMovement::SOURCE_LOSS !== $movement->getSourceType()) {
            throw new StockMovementNotFound();
        }

        return $movement;
    }

    /** @throws StockLossAttachmentNotFound */
    private function attachment(Company $company, StockMovement $loss, Uuid $attachmentId): Attachment
    {
        return $this->attachments->find($company, self::ENTITY_TYPE, $loss->getId(), $attachmentId) ?? throw new StockLossAttachmentNotFound();
    }

    /** The audit row of the loss, and a word to the stock screens, which read the product's movements again. */
    private function recorded(Company $company, StockMovement $loss, string $action, Uuid $attachmentId, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $loss->getId(), $action, $actorUserId, ['attachmentId' => $attachmentId->toRfc4122()], $company->getId()));
        $this->liveChanges->stage(new LiveChange('stock', $loss->getProduct()->getId(), $action, $actorUserId, $company->getId()));
    }
}
