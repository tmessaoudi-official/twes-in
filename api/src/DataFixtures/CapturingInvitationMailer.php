<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataFixtures;

use App\Tenancy\Application\Invitation\InvitationMail;
use App\Tenancy\Application\Invitation\InvitationMailer;
use App\Tenancy\Infrastructure\Mail\SymfonyInvitationMailer;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Remembers where each invitation mail pointed, and still sends it.
 *
 * The demo fixtures add a member per role by INVITING them, which is the product's only path into a company — but
 * an invitation's token is deliberately never returned by the use case, since it belongs in the mail and nowhere
 * else. Observing the port is how a caller learns it without that contract being widened for everybody: the use
 * case is untouched, `InviteOutcome` still carries no token, and no API resource can leak one.
 *
 * It decorates rather than replaces, so the mail still reaches Mailpit and the invitation flow in `docs/START.md`
 * behaves exactly as it did. `#[When]` keeps it out of production entirely, where the fixtures never run.
 *
 * It decorates the ADAPTER by name, not the port: a second class implementing the port removes the single
 * implementation Symfony auto-aliases the interface to, so decorating the interface leaves nothing to decorate.
 * `services.yaml` therefore aliases the port to the adapter explicitly, and that alias resolves to this.
 */
#[When('dev')]
#[When('test')]
#[AsDecorator(SymfonyInvitationMailer::class)]
final class CapturingInvitationMailer implements InvitationMailer
{
    /** @var array<string, string> the accept URL last mailed to each address */
    private array $sent = [];

    public function __construct(#[AutowireDecorated] private readonly InvitationMailer $inner)
    {
    }

    public function send(InvitationMail $mail): void
    {
        $this->sent[$mail->to] = $mail->acceptUrl;
        $this->inner->send($mail);
    }

    /**
     * The token of the invitation last mailed to this address, read off the URL the mail carried.
     *
     * @throws \RuntimeException when nothing was mailed there, which means the invitation never went out
     */
    public function tokenFor(string $email): string
    {
        $url = $this->sent[$email] ?? throw new \RuntimeException(\sprintf('No invitation was mailed to %s.', $email));
        $token = basename((string) parse_url($url, \PHP_URL_PATH));

        return '' !== $token ? $token : throw new \RuntimeException(\sprintf('The invitation mailed to %s carried no token: %s', $email, $url));
    }
}
