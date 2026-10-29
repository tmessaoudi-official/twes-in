<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Quotes\Application;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\DocumentNames;
use App\Fiscal\Application\Regime\ExcludedTaxFamilies;
use App\Fiscal\Domain\Model\CustomerTaxRegime;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Module\Quotes\Application\Delivery\QuoteDeliveryNotes;
use App\Module\Quotes\Application\QuoteInvoices;
use App\Module\Quotes\Application\QuoteNotFound;
use App\Module\Quotes\Application\QuoteSettings;
use App\Module\Quotes\Application\QuoteTotals;
use App\Module\Quotes\Application\QuoteWorkflow;
use App\Module\Quotes\Domain\Exception\InvalidQuote;
use App\Module\Quotes\Domain\Exception\QuoteTransitionRefused;
use App\Module\Quotes\Domain\Model\Quote;
use App\Module\Quotes\Domain\ValueObject\QuoteHeader;
use App\Module\Quotes\Domain\ValueObject\QuoteLineDetails;
use App\Module\Quotes\Domain\ValueObject\QuotePrint;
use App\Module\Quotes\Domain\ValueObject\QuoteStatus;
use App\Settings\Application\BusinessDefaultSettings;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingCatalog;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Shared\Application\Receivers\ChooseReceiver;
use App\Shared\Application\Receivers\ReceiverChoice;
use App\Shared\Domain\ValueObject\PrintSettings;
use App\Shared\Domain\ValueObject\Receiver;
use App\Tenancy\Application\Numbering\AllocateNumber;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryQuotes;
use App\Tests\Support\InMemoryReceiverContacts;
use App\Tests\Support\InMemorySettings;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\KeptNames;
use App\Tests\Support\ShippedFiscalPresets;
use App\Tests\Support\ShippedLanguages;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** Who answered a quote for its customer, as the answer and its correction name them, and as the customer's setting says. */
final class QuoteWorkflowTest extends TestCase
{
    private MockClock $clock;
    private InMemoryUnits $units;
    private InMemoryEstablishments $establishments;
    private InMemoryQuotes $quotes;
    private InMemoryAuditTrail $audit;
    private InMemoryReceiverContacts $contacts;
    private ChangeSettings $change;
    private QuoteWorkflow $workflow;
    private Company $company;
    private Company $globex;
    private Customer $customer;
    private Uuid $actor;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-05 09:00:00', 'UTC');
        $this->units = new InMemoryUnits();
        $this->establishments = new InMemoryEstablishments();
        $series = new InMemoryNumberingSeries();
        $taxes = new InMemoryTaxComponents();
        $provision = new ProvisionCompany(ShippedFiscalPresets::presets(), $taxes, $this->units, $this->establishments, $series, ShippedFiscalPresets::scales(), $this->clock);
        $provision->handle($this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'));
        $provision->handle($this->globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'));
        $transactions = new FakeTransactions();
        $this->audit = new InMemoryAuditTrail($transactions);
        $settings = new InMemorySettings();
        $catalog = new SettingCatalog([new BusinessDefaultSettings(ShippedLanguages::catalogue()), new QuoteSettings()]);
        $resolve = new ResolveSettings($catalog, $settings);
        $this->change = new ChangeSettings($catalog, $settings, $resolve, new InMemoryAuditTrail($settingTransactions = new FakeTransactions()), $this->clock, $settingTransactions, new \App\Tests\Support\RecordingDomainEvents(), new \App\Shared\Application\TypedPhones(new \App\Shared\Infrastructure\Intl\LibPhoneNumbers(new \Symfony\Component\Translation\IdentityTranslator())));
        $this->workflow = new QuoteWorkflow(
            $this->quotes = new InMemoryQuotes(),
            new AllocateNumber($series, $transactions, $this->clock),
            $transactions,
            new QuoteTotals(ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales()),
            $this->audit,
            $this->clock,
            new ReadSetting($resolve),
            self::noInvoices(),
            new DocumentNames($taxes),
            new ExcludedTaxFamilies(ShippedFiscalPresets::presets()),
            self::noDeliveries(),
            new ChooseReceiver($this->contacts = new InMemoryReceiverContacts()),
        );
        $this->customer = Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $this->clock->now()), [], $this->clock->now());
        $this->actor = Uuid::v7();
    }

    public function testAnAnswerNamesAContactOrANameTypedAndAddsItToTheContactsOnlyWhenAsked(): void
    {
        $ahmed = Uuid::v7();
        $this->contacts->contacts[$this->customer->getId()->toRfc4122()] = [$ahmed->toRfc4122() => 'Ahmed Ben Salah'];

        $accepted = $this->workflow->accept($this->company, $this->sent()->getId(), null, $this->actor, new ReceiverChoice($ahmed->toRfc4122()));
        self::assertEquals(Receiver::named('Ahmed Ben Salah', $ahmed), $accepted->getReceivedBy(), 'a contact is copied by its name');

        $refused = $this->workflow->refuse($this->company, $this->sent()->getId(), null, 'Trop cher.', $this->actor, new ReceiverChoice(name: ' Sami Trabelsi '));
        self::assertEquals(Receiver::named('Sami Trabelsi'), $refused->getReceivedBy(), 'a name typed, trimmed, names no contact');
        self::assertSame([], $this->contacts->added, 'nothing is added to the contacts unless asked');

        $added = $this->workflow->accept($this->company, $this->sent()->getId(), null, $this->actor, new ReceiverChoice(name: 'Leila Mansour', addToContacts: true));
        self::assertSame([[$this->customer->getId()->toRfc4122(), 'Leila Mansour']], $this->contacts->added);
        self::assertNotNull($added->getReceivedBy()?->contactId, 'the contact added is the one named');

        $nobody = $this->workflow->accept($this->company, $this->sent()->getId(), null, $this->actor);
        self::assertNull($nobody->getReceivedBy());
    }

    public function testTheCustomersSettingRequiresOrRefusesWhoAnsweredAndARefusalAnswersNothing(): void
    {
        $this->rule('required');
        $quote = $this->sent();
        foreach (['accept' => fn () => $this->workflow->accept($this->company, $quote->getId(), null, $this->actor), 'refuse' => fn () => $this->workflow->refuse($this->company, $quote->getId(), null, null, $this->actor)] as $case => $answer) {
            try {
                $answer();
                self::fail("$case naming nobody was taken where the customer's quotes require someone");
            } catch (InvalidQuote $refused) {
                self::assertSame(['receivedBy', 'receiver_required'], [$refused->field, $refused->reason], $case);
            }
        }
        self::assertSame(QuoteStatus::Sent, $quote->getStatus());
        self::assertSame([], $this->audit->entries, 'a refused answer records nothing');

        $this->rule('off');
        try {
            $this->workflow->accept($this->company, $quote->getId(), null, $this->actor, new ReceiverChoice(name: 'Sami'));
            self::fail('someone was named where the customer\'s quotes name nobody');
        } catch (InvalidQuote $refused) {
            self::assertSame(['receivedBy', 'receiver_off'], [$refused->field, $refused->reason]);
        }

        $other = Customer::create($this->company, 'CLI-0002', new CustomerProfile(CustomerKind::Company, 'Autre'), null, new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $this->clock->now()), [], $this->clock->now());
        $theirs = Uuid::v7();
        $this->contacts->contacts[$other->getId()->toRfc4122()] = [$theirs->toRfc4122() => 'Someone Else'];
        $this->rule('optional');
        try {
            $this->workflow->accept($this->company, $quote->getId(), null, $this->actor, new ReceiverChoice($theirs->toRfc4122()));
            self::fail('another customer\'s contact answered');
        } catch (InvalidQuote $refused) {
            self::assertSame(['receivedBy', 'unknown_choice'], [$refused->field, $refused->reason]);
        }
        self::assertSame(QuoteStatus::Sent, $quote->getStatus());
    }

    public function testWhoAnsweredIsPutRightRecordingTheFieldNeverTheNameAndOnlyWhenItChanges(): void
    {
        $quote = $this->workflow->accept($this->company, $this->sent()->getId(), null, $this->actor, new ReceiverChoice(name: 'Sami Trabelsy'));

        $this->workflow->correctReceiver($this->company, $quote->getId(), new ReceiverChoice(name: 'Sami Trabelsi'), $this->actor);
        self::assertEquals(Receiver::named('Sami Trabelsi'), $quote->getReceivedBy());
        $this->workflow->correctReceiver($this->company, $quote->getId(), new ReceiverChoice(name: 'Sami Trabelsi'), $this->actor);
        $corrections = array_values(array_filter($this->audit->entries, static fn ($entry): bool => QuoteWorkflow::RECEIVER_CORRECTED === $entry->action));
        self::assertCount(1, $corrections, 'sent again unchanged, nothing is recorded');
        self::assertSame(['fields' => ['receivedBy']], $corrections[0]->changes, 'the journal keeps the field, never the name');

        $this->rule('required');
        try {
            $this->workflow->correctReceiver($this->company, $quote->getId(), null, $this->actor);
            self::fail('nobody was named where the customer\'s quotes require someone');
        } catch (InvalidQuote $refused) {
            self::assertSame(['receivedBy', 'receiver_required'], [$refused->field, $refused->reason]);
        }
        $this->rule('off');
        $this->workflow->correctReceiver($this->company, $quote->getId(), null, $this->actor);
        self::assertNull($quote->getReceivedBy(), 'switched off, the name given by mistake is taken back');

        $this->expectException(QuoteTransitionRefused::class);
        $this->workflow->correctReceiver($this->company, $this->sent()->getId(), new ReceiverChoice(name: 'Sami'), $this->actor);
    }

    public function testAnotherCompanysQuoteIsNotThere(): void
    {
        $quote = $this->workflow->accept($this->company, $this->sent()->getId(), null, $this->actor);

        $this->expectException(QuoteNotFound::class);
        $this->workflow->correctReceiver($this->globex, $quote->getId(), new ReceiverChoice(name: 'Sami'), $this->actor);
    }

    private function sent(): Quote
    {
        $establishment = $this->establishments->ofCompany($this->company->getId())[0];
        $unit = $this->units->ofCodeInCompany('C62', $this->company->getId()) ?? throw new \LogicException('no unit');
        $quote = Quote::create($this->company, $establishment, $this->customer, new QuoteHeader(), [new QuoteLineDetails(null, 'Pièce', '1', $unit, '10', null, [])], $this->clock->now());
        $quote->send('DEV-'.\count($this->quotes->quotes), new \DateTimeImmutable('2026-10-01'), 30, new QuotePrint('fr', true, new PrintSettings('', 'auto', 'auto')), KeptNames::of($quote), $this->clock->now());
        $this->quotes->save($quote);

        return $quote;
    }

    private function rule(string $rule): void
    {
        $this->change->change(new SettingContext($this->company, customerId: $this->customer->getId()), QuoteSettings::RECEIVER, SettingLevel::Customer, $rule, null);
    }

    /** The invoices, which the receiver's path never reaches: any call is a test's mistake. */
    private static function noInvoices(): QuoteInvoices
    {
        return new class implements QuoteInvoices {
            public function draftFrom(Quote $quote, ?Uuid $actorUserId): Uuid
            {
                throw new \LogicException('not on the receiver\'s path');
            }

            public function addTo(Quote $quote, Uuid $invoiceId, ?Uuid $actorUserId): void
            {
                throw new \LogicException('not on the receiver\'s path');
            }

            public function depositFrom(Quote $quote, ?string $percentage, ?string $amount, ?Uuid $actorUserId): Uuid
            {
                throw new \LogicException('not on the receiver\'s path');
            }

            public function depositsOf(Company $company, array $quoteIds): array
            {
                throw new \LogicException('not on the receiver\'s path');
            }

            public function stands(Company $company, Uuid $invoiceId): bool
            {
                throw new \LogicException('not on the receiver\'s path');
            }
        };
    }

    /** The delivery notes, which the receiver's path never reaches either. */
    private static function noDeliveries(): QuoteDeliveryNotes
    {
        return new class implements QuoteDeliveryNotes {
            public function ofQuotes(Company $company, array $lineIdsByQuote): array
            {
                throw new \LogicException('not on the receiver\'s path');
            }
        };
    }
}
