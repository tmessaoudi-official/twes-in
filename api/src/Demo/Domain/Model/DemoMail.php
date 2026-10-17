<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A mail the application sent to a sandbox's owner, kept instead of sent: a demo's mail never leaves, and the visitor
 * reads it on « Courriers de la démo ». It goes with its sandbox.
 */
#[ORM\Entity]
#[ORM\Table(name: 'demo_mail')]
#[ORM\Index(name: 'idx_demo_mail_sandbox_captured', columns: ['sandbox_id', 'captured_at'])]
class DemoMail
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: DemoSandbox::class)]
    #[ORM\JoinColumn(name: 'sandbox_id', nullable: false, onDelete: 'CASCADE')]
    private DemoSandbox $sandbox;

    #[ORM\Column(type: 'text')]
    private string $subject;

    #[ORM\Column(type: 'text')]
    private string $html;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $capturedAt;

    public function __construct(Uuid $id, DemoSandbox $sandbox, string $subject, string $html, \DateTimeImmutable $capturedAt)
    {
        $this->id = $id;
        $this->sandbox = $sandbox;
        $this->subject = $subject;
        $this->html = $html;
        $this->capturedAt = $capturedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSandbox(): DemoSandbox
    {
        return $this->sandbox;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getHtml(): string
    {
        return $this->html;
    }

    public function getCapturedAt(): \DateTimeImmutable
    {
        return $this->capturedAt;
    }
}
