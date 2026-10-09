<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\PasswordHasher;
use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Inbox\Application\NotificationPreferences;
use App\Inbox\Infrastructure\Mail\NotificationToMail;
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Role;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email as MimeEmail;

/**
 * Each kind told to a person is also mailed to them, by the worker, in their own language, unless they turned that kind's
 * e-mail off; the mail carries a link that turns it off, which opens a page whose button does it, never the link itself.
 */
final class NotificationMailTest extends ApiTestCase
{
    private const string PREFERENCES = '/api/me/notification-preferences';
    private const string STOP = '/api/notification-preferences/stop';
    private const array PRODUCT = ['product' => 'Vis M6', 'reference' => 'VIS-6', 'on_hand' => '3', 'establishment' => 'Siège', 'point' => '10'];

    private Company $acme;
    private User $keeper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltInRoles();
        $this->acme = $this->createCompany('Acme');
        $this->keeper = $this->member('keeper@twes.local', 'en');
    }

    public function testAKindToldIsMailedByTheWorkerInThePersonsLanguageWithALinkThatTurnsItsMailOff(): void
    {
        $this->tellStockLow();

        $queued = $this->queued();
        self::assertCount(1, $queued, 'the request queues the mail; the worker sends it');
        self::assertInstanceOf(NotificationToMail::class, $queued[0]);
        self::assertEmailCount(0);

        self::assertSame(1, $this->deliverQueued());
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(MimeEmail::class, $mail);
        self::assertSame('keeper@twes.local', $mail->getTo()[0]->getAddress());
        self::assertSame('Stock under its reorder point · Acme', $mail->getSubject());
        $body = (string) $mail->getHtmlBody();
        self::assertStringContainsString('Vis M6 (VIS-6) is down to 3 at Siège, at or under its reorder point of 10.', $body);
        self::assertStringContainsString('lang="en"', $body);
        $stop = self::stopLinkIn($body);
        self::assertStringStartsWith(self::appUrl().'/notifications/stop/', $stop);
        self::assertSame("<$stop>", $mail->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());
    }

    public function testNoMailIsSentForAKindWhoseMailWasTurnedOffBeforeTheWorkerSendsIt(): void
    {
        $this->tellStockLow();
        // Chosen without a request: a request reboots the kernel, and the queue under test with it.
        static::getContainer()->get(NotificationPreferences::class)->change($this->keeper->getId(), $this->acme->getId(), 'stock.low', true, false);

        self::assertSame(1, $this->deliverQueued(), 'the worker had the mail to send');
        self::assertEmailCount(0);
    }

    public function testNoMailReachesSomebodyNoLongerInTheCompanyWhenTheWorkerSendsIt(): void
    {
        $this->tellStockLow();
        $membership = $this->em()->getRepository(Membership::class)->findOneBy(['user' => $this->keeper->getId(), 'company' => $this->acme->getId()]);
        self::assertNotNull($membership);
        $this->em()->remove($membership);
        $this->em()->flush();

        self::assertSame(1, $this->deliverQueued(), 'the worker had the mail to send');
        self::assertEmailCount(0);
    }

    public function testAKindMailedApartIsNotMailedAgainAndItsMailSwitchSaysSo(): void
    {
        $this->notifications()->publish(new Notification('user:'.$this->keeper->getId()->toRfc4122(), 'invitation.received', ['company' => 'Globex']));

        self::assertSame([], $this->queued(), 'the invitation mail itself is how an invitation is told');

        $this->login('keeper@twes.local', 'password-1234');
        $this->getJson(self::PREFERENCES);
        $mailed = [];
        foreach ($this->arrayAt($this->json(), 'preferences') as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['type']);
            $mailed[$row['type']] = $row['mailed'];
        }
        self::assertFalse($mailed['invitation.received']);
        self::assertTrue($mailed['stock.low']);
    }

    public function testTheLinkTurnsOffThatKindsMailInThatCompanyAndNothingElseWithNoSession(): void
    {
        $this->tellStockLow();
        $this->deliverQueued();
        $mail = self::getMailerMessage();
        self::assertInstanceOf(MimeEmail::class, $mail);
        $token = self::tokenIn((string) $mail->getHtmlBody());

        $this->client->restart();
        $this->postJson(self::STOP, ['token' => $token]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->postJson(self::STOP, ['token' => $token]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT, 'a link followed twice says the same');

        $this->login('keeper@twes.local', 'password-1234');
        $this->getJson(self::PREFERENCES);
        $rows = [];
        foreach ($this->arrayAt($this->json(), 'preferences') as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['type']);
            $rows[$row['type']] = [$row['bell'], $row['email']];
        }
        self::assertSame([true, false], $rows['stock.low'], 'the bell stays as it was');
        self::assertSame([true, true], $rows['invitation.accepted']);

        $this->tellStockLow();
        self::assertSame(1, $this->deliverQueued(), 'the worker had the mail to send');
        self::assertEmailCount(0);
    }

    public function testALinkNotSignedHereStopsNothingAndAGetChangesNothing(): void
    {
        $this->tellStockLow();
        $this->deliverQueued();
        $mail = self::getMailerMessage();
        self::assertInstanceOf(MimeEmail::class, $mail);
        $token = self::tokenIn((string) $mail->getHtmlBody());
        [$payload, $signature] = explode('.', $token);
        $this->client->restart();

        foreach ([
            'another signature' => $payload.'.'.strrev($signature),
            'no signature' => $payload,
            'nothing' => '',
            'not even text' => 42,
        ] as $case => $forged) {
            $this->postJson(self::STOP, ['token' => $forged]);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $case);
        }
        $this->client->request('GET', self::STOP.'?token='.$token);
        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED, 'a mail client opening a link must change nothing');
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM notification_preference'));
    }

    private function tellStockLow(): void
    {
        $this->notifications()->publish(new Notification('user:'.$this->keeper->getId()->toRfc4122(), 'stock.low', self::PRODUCT, $this->acme->getId()->toRfc4122()));
    }

    private function notifications(): Notifications
    {
        return static::getContainer()->get(Notifications::class);
    }

    private function member(string $email, string $locale): User
    {
        $em = $this->em();
        $user = new User(Email::fromString($email), 'Keeper', $locale);
        $user->setPasswordHash(static::getContainer()->get(PasswordHasher::class)->hash('password-1234'), new \DateTimeImmutable());
        $em->persist($user);
        $role = new Role(Role::MEMBER, ['stock.write', 'stock.read', 'company.read'], $this->acme);
        $em->persist($role);
        $em->persist(new Membership($user, $this->acme, $role));
        $em->flush();

        return $user;
    }

    private static function stopLinkIn(string $body): string
    {
        if (1 !== preg_match('#href="([^"]*/notifications/stop/[^"]+)"#', $body, $match)) {
            self::fail('the mail carries the stop link');
        }

        return html_entity_decode($match[1]);
    }

    private static function tokenIn(string $body): string
    {
        return substr(self::stopLinkIn($body), \strlen(self::appUrl().'/notifications/stop/'));
    }

    /** Where the SPA is served, which every link a mail carries starts with. */
    private static function appUrl(): string
    {
        $url = $_SERVER['DEFAULT_URI'] ?? $_ENV['DEFAULT_URI'] ?? null;
        self::assertIsString($url);

        return $url;
    }
}
