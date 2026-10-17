<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Demo\Application\CaptureDemoMail;
use App\Demo\Domain\Model\DemoSandbox;
use App\Identity\Application\Password\PasswordResetMail;
use App\Identity\Application\Password\PasswordResetMailer;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * A demo's mail never leaves: whatever the application sends a sandbox's owner is kept for « Courriers de la démo » and
 * rejected before the transport, and every other mail goes on as before.
 */
final class DemoMailTest extends ApiTestCase
{
    use MakesSandboxes;

    private const string PASSWORD = 'not-used-here';

    /** @var list<string> every address the transport sent a mail to, copies included */
    private array $sent = [];

    /**
     * Hears what the transport actually sends: a rejected mail raises no `SentMessageEvent`, and stops its
     * `MessageEvent` before the mailer's own logger, which therefore cannot say it was rejected.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->sent = [];
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(SentMessageEvent::class, function (SentMessageEvent $event): void {
            foreach ($event->getMessage()->getEnvelope()->getRecipients() as $recipient) {
                $this->sent[] = $recipient->getAddress();
            }
        });
    }

    public function testAMailToASandboxOwnerIsKeptAsRenderedAndNeverSent(): void
    {
        $sandbox = $this->readySandbox('TN');
        $this->em()->flush();

        static::getContainer()->get(PasswordResetMailer::class)->send(new PasswordResetMail($this->addressOf($sandbox), 'https://twes.test/reset-password/abc', 'fr', 30));

        self::assertSame([], $this->sent, 'the transport never sends it');
        $kept = $this->row('SELECT subject, html FROM demo_mail WHERE sandbox_id = ?', [$sandbox->getId()->toRfc4122()]);
        self::assertIsString($kept['html']);
        self::assertStringContainsString('https://twes.test/reset-password/abc', $kept['html'], 'kept as Twig rendered it, so it reads as it would have arrived');
        self::assertIsString($kept['subject']);
        self::assertNotSame('', $kept['subject']);
        self::assertSame(1, $this->numberOf(
            'SELECT COUNT(*) FROM audit_log WHERE action = ? AND company_id = ? AND actor_user_id IS NULL',
            [CaptureDemoMail::CAPTURED, ($sandbox->getCompanyId() ?? self::fail('A ready sandbox has a company.'))->toRfc4122()],
        ), 'a line of the sandbox company\'s journal, which is what tells its open page to read again');
    }

    public function testEveryOtherMailGoesOnAsBefore(): void
    {
        $this->readySandbox('FR');
        $this->em()->flush();

        static::getContainer()->get(PasswordResetMailer::class)->send(new PasswordResetMail('someone@example.test', 'https://twes.test/reset-password/xyz', 'fr', 30));

        self::assertSame(['someone@example.test'], $this->sent);
        self::assertSame(0, $this->numberOf('SELECT COUNT(*) FROM demo_mail', []));
    }

    public function testASandboxStillBeingBuiltKeepsItsMailToo(): void
    {
        // The dataset's dated steps run before the sandbox is ready, and what they send must not leave either.
        $sandbox = $this->openedSandbox('FR', '-1 minute');
        $this->em()->flush();

        $this->send((new Email())->from('twes@example.test')->to(strtoupper($this->addressOf($sandbox)))->subject('Rappel')->text('Une facture est en retard.'));

        self::assertSame([], $this->sent, 'an address is matched whatever its case');
        self::assertSame(['Rappel', '<pre>Une facture est en retard.</pre>'], array_values($this->row('SELECT subject, html FROM demo_mail WHERE sandbox_id = ?', [$sandbox->getId()->toRfc4122()])));
    }

    public function testAMailNamingASandboxOwnerAmongOthersIsSentToNone(): void
    {
        $sandbox = $this->readySandbox('TN');
        $this->em()->flush();

        $this->send((new Email())->from('twes@example.test')->to('someone@example.test')->bcc($this->addressOf($sandbox))->subject('Copie')->html('<p>Copie</p>'));

        self::assertSame([], $this->sent, 'it speaks of a made-up company, so nobody gets it');
        self::assertSame(1, $this->numberOf('SELECT COUNT(*) FROM demo_mail WHERE sandbox_id = ?', [$sandbox->getId()->toRfc4122()]));
    }

    public function testTheOwnerReadsTheMailsKeptTheLatestFirst(): void
    {
        $sandbox = $this->readySandbox('FR');
        $this->em()->flush();
        $address = $this->addressOf($sandbox);
        $this->send((new Email())->from('twes@example.test')->to($address)->subject('Premier')->html('<p>1</p>'));
        $this->send((new Email())->from('twes@example.test')->to($address)->subject('Second')->html('<p>2</p>'));
        $this->login($address, self::PASSWORD);

        $this->getJson(\sprintf('/api/companies/%s/demo-mails', self::companyOf($sandbox)));

        self::assertResponseIsSuccessful();
        $mails = $this->json()['mails'] ?? self::fail('No mails.');
        self::assertIsArray($mails);
        self::assertSame(['Second', 'Premier'], array_column($mails, 'subject'));
        self::assertSame(['id', 'subject', 'capturedAt', 'html'], array_keys(\is_array($mails[0]) ? $mails[0] : []));
        self::assertSame('<p>2</p>', \is_array($mails[0]) ? $mails[0]['html'] : null);
    }

    public function testACompanyThatIsNoSandboxHasNoMailsToShow(): void
    {
        $company = $this->createCompany();
        $this->createUser('owner@example.test', self::PASSWORD, $company);
        $this->login('owner@example.test', self::PASSWORD);

        $this->getJson(\sprintf('/api/companies/%s/demo-mails', $company->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testNobodyElseReadsASandboxsMails(): void
    {
        $sandbox = $this->readySandbox('TN');
        $this->createUser('stranger@example.test', self::PASSWORD, $this->createCompany());
        $this->em()->flush();
        $this->login('stranger@example.test', self::PASSWORD);

        $this->getJson(\sprintf('/api/companies/%s/demo-mails', self::companyOf($sandbox)));

        self::assertResponseStatusCodeSame(404);
    }

    public function testRemovingTheSandboxRemovesItsMails(): void
    {
        $sandbox = $this->readySandbox('FR');
        $this->em()->flush();
        $this->send((new Email())->from('twes@example.test')->to($this->addressOf($sandbox))->subject('Bientôt parti')->html('<p>x</p>'));

        $this->em()->getConnection()->executeStatement('DELETE FROM demo_sandbox WHERE id = ?', [$sandbox->getId()->toRfc4122()]);

        self::assertSame(0, $this->numberOf('SELECT COUNT(*) FROM demo_mail', []));
    }

    private function send(Email $email): void
    {
        static::getContainer()->get(MailerInterface::class)->send($email);
    }

    private function addressOf(DemoSandbox $sandbox): string
    {
        $owner = $sandbox->getOwnerId() ?? self::fail('A sandbox opened has an owner.');
        $email = $this->em()->getConnection()->fetchOne('SELECT email FROM "user" WHERE id = ?', [$owner->toRfc4122()]);

        return \is_string($email) ? $email : self::fail('The owner has an address.');
    }

    private static function companyOf(DemoSandbox $sandbox): string
    {
        return ($sandbox->getCompanyId() ?? self::fail('A sandbox opened has a company.'))->toRfc4122();
    }
}
