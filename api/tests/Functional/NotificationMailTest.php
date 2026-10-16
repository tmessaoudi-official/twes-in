<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\PasswordHasher;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\ValueObject\Email;
use App\Inbox\Application\NotificationPreferences;
use App\Inbox\Infrastructure\Mail\NotificationToMail;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Role;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email as MimeEmail;
use Symfony\Component\Uid\Uuid;

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

    public function testAnInvoiceNoticesAmountsAreWrittenAsItsReadersLanguageWritesThemAtItsCurrencysDecimals(): void
    {
        $lea = $this->member('lea@twes.local', 'fr', 'invoicer', ['invoice.issue', 'invoice.read', 'company.read']);
        $sam = $this->member('sam@twes.local', 'en', 'invoicer', null);
        $told = ['customer' => 'Atlas', 'number' => 'F-7', 'stage' => '1', 'days_late' => '12'];
        $this->tell($lea, 'invoice.credit_limit_passed', $told + ['owed' => '1234.5', 'limit' => '1000', 'currency' => 'EUR', 'scale' => 2]);
        $this->tell($sam, 'invoice.reminder_due', $told + ['amount_due' => '1500.5', 'currency' => 'TND', 'scale' => 3]);
        $this->tell($lea, 'invoice.late_fee_drafted', $told + ['amount_due' => '12000', 'late_fee' => '35.000', 'currency' => 'JPY', 'scale' => 0]);
        self::assertSame(3, $this->deliverQueued());

        $bodies = array_map(static fn (MimeEmail $mail): string => (string) $mail->getHtmlBody(), array_values(array_filter(self::getMailerMessages(), static fn ($mail): bool => $mail instanceof MimeEmail)));
        self::assertCount(3, $bodies);
        // French groups thousands with a no-break space and writes a decimal comma; English, a comma and a decimal point.
        self::assertStringContainsString("crédit\u{a0}: 1\u{a0}234,50 EUR dus pour 1\u{a0}000,00 après la facture F-7.", $bodies[0]);
        self::assertStringContainsString('invoice F-7 of Atlas, 1,500.500 TND, is 12 days late.', $bodies[1]);
        // A currency with no decimals has no decimal part at all.
        self::assertStringContainsString("Atlas, 12\u{a0}000 JPY, a 12 jours de retard. Frais de retard de 35 JPY préparés", $bodies[2]);
    }

    public function testAnOlderNoticeWithoutItsDecimalsKeepsItsAmountsAsSent(): void
    {
        $lea = $this->member('lea@twes.local', 'fr', 'invoicer', ['invoice.issue', 'invoice.read', 'company.read']);
        $this->tell($lea, 'invoice.reminder_due', ['customer' => 'Atlas', 'number' => 'F-7', 'stage' => '1', 'days_late' => '12', 'amount_due' => '1500.5', 'currency' => 'TND']);
        self::assertSame(1, $this->deliverQueued());

        $mail = self::getMailerMessage();
        self::assertInstanceOf(MimeEmail::class, $mail);
        self::assertStringContainsString('Atlas, 1500.5 TND, a 12 jours', (string) $mail->getHtmlBody());
    }

    public function testAQuantityIsWrittenAsItsReadersLanguageWritesNumbersWithNoTrailingZero(): void
    {
        $lea = $this->member('lea@twes.local', 'fr', 'keeper');
        $this->tell($this->keeper, 'stock.low', ['on_hand' => '3.500', 'point' => '1250.000'] + self::PRODUCT);
        $this->tell($lea, 'stock.count_difference', ['product' => 'Vis M6', 'reference' => 'VIS-6', 'location' => 'A1', 'difference' => '-2.000']);
        self::assertSame(2, $this->deliverQueued());

        $bodies = $this->bodiesTo();
        self::assertStringContainsString('Vis M6 (VIS-6) is down to 3.5 at Siège, at or under its reorder point of 1,250.', $bodies['keeper@twes.local']);
        self::assertStringContainsString('à A1 a trouvé un écart de -2.', $bodies['lea@twes.local']);
    }

    public function testADayAndAMomentAreWrittenAsTheReaderChoseAndEachReaderInTurnGetsTheirOwn(): void
    {
        $owner = $this->member('owner@twes.local', 'fr', Role::OWNER, ['*']);
        $other = $this->member('other@twes.local', 'en', Role::OWNER, null);
        $clerk = $this->member('clerk@twes.local', 'fr', 'clerk', ['expense.write', 'expense.read', 'company.read']);
        $this->choose($owner, 'presentation.date-format', 'mdy');
        $this->choose($clerk, 'presentation.date-format', 'dmy-dots');
        $granted = ['company' => 'Acme', 'by' => 'Léa', 'until' => '2027-01-29 18:30'];
        $this->tell($owner, 'support_access.granted', $granted);
        $this->tell($other, 'support_access.granted', $granted);
        $this->tell($clerk, 'expense.recurring_drafted', ['description' => 'Loyer', 'dueOn' => '2027-02-01', 'expenseId' => Uuid::v7()->toRfc4122()]);
        self::assertSame(3, $this->deliverQueued());

        $bodies = $this->bodiesTo();
        self::assertStringContainsString('en lecture seule, jusqu’au 01/29/2027 18:30.', $bodies['owner@twes.local']);
        // The next reader is not written with the one before's choice: the language's own order.
        self::assertStringContainsString('read-only, until 29/01/2027 18:30.', $bodies['other@twes.local']);
        self::assertStringContainsString('un brouillon du 01.02.2027 attend', $bodies['clerk@twes.local']);
    }

    public function testTheReadersNumberFormatWinsOverTheLanguageForAmountsAndQuantities(): void
    {
        $lea = $this->member('lea@twes.local', 'fr', 'invoicer', ['invoice.issue', 'invoice.read', 'stock.read', 'stock.write', 'company.read']);
        $this->choose($lea, 'presentation.number-format', 'comma-dot');
        $this->tell($lea, 'invoice.credit_limit_passed', ['customer' => 'Atlas', 'number' => 'F-7', 'owed' => '1234.5', 'limit' => '1000', 'currency' => 'EUR', 'scale' => 2]);
        $this->tell($lea, 'stock.low', ['on_hand' => '1234.5', 'point' => '2000.000'] + self::PRODUCT);
        self::assertSame(2, $this->deliverQueued());

        // Both went to Léa: read them together.
        $bodies = implode("\n", array_map(static fn (MimeEmail $mail): string => (string) $mail->getHtmlBody(), array_filter(self::getMailerMessages(), static fn ($mail): bool => $mail instanceof MimeEmail)));
        self::assertStringContainsString("crédit\u{a0}: 1,234.50 EUR dus pour 1,000.00 après la facture F-7.", $bodies);
        self::assertStringContainsString('est tombé à 1,234.5 à Siège, au niveau ou sous son seuil de réapprovisionnement de 2,000.', $bodies);
    }

    public function testTheCompanysFormatReachesAReaderWhoChoseNoneOfTheirOwn(): void
    {
        static::getContainer()->get(ChangeSettings::class)->change(new SettingContext($this->acme), 'presentation.number-format', SettingLevel::Company, 'dot-comma', null);
        $this->tell($this->keeper, 'stock.low', ['on_hand' => '1234.5', 'point' => '2000'] + self::PRODUCT);
        self::assertSame(1, $this->deliverQueued());

        self::assertStringContainsString('is down to 1.234,5 at Siège, at or under its reorder point of 2.000.', $this->bodiesTo()['keeper@twes.local']);
    }

    public function testAnOlderNoticeWhoseDayOrQuantityIsNotOneIsKeptAsSent(): void
    {
        $this->tell($this->keeper, 'stock.low', ['on_hand' => 'about 3', 'point' => '10'] + self::PRODUCT);
        $owner = $this->member('owner@twes.local', 'en', Role::OWNER, ['*']);
        $this->tell($owner, 'support_access.granted', ['company' => 'Acme', 'by' => 'Léa', 'until' => 'Friday']);
        self::assertSame(2, $this->deliverQueued());

        $bodies = $this->bodiesTo();
        self::assertStringContainsString('is down to about 3 at Siège, at or under its reorder point of 10.', $bodies['keeper@twes.local']);
        self::assertStringContainsString('read-only, until Friday.', $bodies['owner@twes.local']);
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

    public function testWhatACompanyIsToldOfSomebodysActIsNotToldToThatPersonNorMailedToThem(): void
    {
        $em = $this->em();
        $owner = new User(Email::fromString('owner@twes.local'), 'Owner', 'fr');
        $em->persist($owner);
        $role = new Role('barista', ['company.read'], $this->acme);
        $em->persist($role);
        $em->persist(new Membership($owner, $this->acme, $role));
        $em->flush();

        $this->notifications()->publish(new Notification(
            'company:'.$this->acme->getId()->toRfc4122(),
            'invitation.accepted',
            ['user_id' => $this->keeper->getId()->toRfc4122(), 'display_name' => 'Keeper', 'role' => 'member'],
            actorId: $this->keeper->getId()->toRfc4122(),
        ));

        $told = $this->em()->getConnection()->fetchFirstColumn("SELECT recipient_id FROM inbox_item WHERE type = 'invitation.accepted'");
        self::assertSame([$owner->getId()->toRfc4122()], array_map(static function (mixed $id): string {
            self::assertIsString($id);

            return Uuid::fromString($id)->toRfc4122();
        }, $told));
        $mailed = array_map(static function (object $message): string {
            self::assertInstanceOf(NotificationToMail::class, $message);

            return $message->userId;
        }, $this->queued());
        self::assertSame([$owner->getId()->toRfc4122()], $mailed);
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

        // What the token names is the whole of what it may stop: the same signature over a rewritten person, company or
        // kind must stop nothing, or one link would turn off anyone's mail.
        $named = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        self::assertIsArray($named);
        $rewritten = static fn (array $change): string => rtrim(strtr(base64_encode(json_encode([...$named, ...$change], \JSON_THROW_ON_ERROR)), '+/', '-_'), '=').'.'.$signature;

        foreach ([
            'another signature' => $payload.'.'.strrev($signature),
            'no signature' => $payload,
            'nothing' => '',
            'not even text' => 42,
            'another person, the same signature' => $rewritten(['u' => Uuid::v7()->toRfc4122()]),
            'another company, the same signature' => $rewritten(['c' => Uuid::v7()->toRfc4122()]),
            'another kind, the same signature' => $rewritten(['t' => 'invitation.accepted']),
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

    /** @param array<string, scalar|null> $payload */
    private function tell(User $user, string $type, array $payload): void
    {
        $this->notifications()->publish(new Notification('user:'.$user->getId()->toRfc4122(), $type, $payload, $this->acme->getId()->toRfc4122()));
    }

    /** @param list<string>|null $permissions the role's, made with them; null joins the role already made under that name */
    private function member(string $email, string $locale, string $roleName = Role::MEMBER, ?array $permissions = ['stock.write', 'stock.read', 'company.read']): User
    {
        $em = $this->em();
        $user = new User(Email::fromString($email), 'Keeper', $locale);
        $user->setPasswordHash(static::getContainer()->get(PasswordHasher::class)->hash('password-1234'), new \DateTimeImmutable());
        $em->persist($user);
        $role = null === $permissions ? $em->getRepository(Role::class)->findOneBy(['name' => $roleName, 'company' => $this->acme]) : new Role($roleName, $permissions, $this->acme);
        self::assertInstanceOf(Role::class, $role);
        $em->persist($role);
        $em->persist(new Membership($user, $this->acme, $role));
        $em->flush();

        return $user;
    }

    /** @return array<string, string> each mail's body, by the address it went to */
    private function bodiesTo(): array
    {
        $bodies = [];
        foreach (self::getMailerMessages() as $mail) {
            if ($mail instanceof MimeEmail) {
                $bodies[$mail->getTo()[0]->getAddress()] = (string) $mail->getHtmlBody();
            }
        }

        return $bodies;
    }

    /** The person's own choice of a presentation key, as « Mon compte » writes it. */
    private function choose(User $user, string $key, string $value): void
    {
        static::getContainer()->get(ChangeSettings::class)->change(new SettingContext($this->acme, userId: $user->getId()), $key, SettingLevel::User, $value, $user->getId());
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
