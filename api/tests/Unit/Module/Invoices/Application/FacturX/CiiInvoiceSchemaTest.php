<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Invoices\Application\FacturX;

use App\Module\Invoices\Application\FacturX\CiiInvoiceXml;
use PHPUnit\Framework\TestCase;

/**
 * The written file against the published schemas, which are not in this repository (their licence is not settled for
 * it): FACTURX_XSD_DIR names the directory holding Factur-X_EN16931.xsd from the Factur-X package, and EN16931_CII_XSD
 * the UN/CEFACT CrossIndustryInvoice_100pD16B.xsd from the CEN validation artefacts. Each case is skipped without its
 * variable, as CI has neither.
 */
final class CiiInvoiceSchemaTest extends TestCase
{
    public function testTheFilesAreValidAgainstTheFacturXEn16931Schema(): void
    {
        $schema = self::schema('FACTURX_XSD_DIR', 'Factur-X_EN16931.xsd');

        foreach (['a standard invoice' => CiiInvoiceXmlTest::standard(), 'a credit note' => CiiInvoiceXmlTest::creditNote()] as $case => $document) {
            self::assertSame([], self::errors(new CiiInvoiceXml()->write($document), $schema), $case);
        }
    }

    public function testTheFilesAreValidAgainstTheUnCefactD16bSchema(): void
    {
        $schema = self::schema('EN16931_CII_XSD', null);

        foreach (['a standard invoice' => CiiInvoiceXmlTest::standard(), 'a credit note' => CiiInvoiceXmlTest::creditNote()] as $case => $document) {
            self::assertSame([], self::errors(new CiiInvoiceXml()->write($document), $schema), $case);
        }
    }

    public function testTheSchemaRefusesAnElementOutOfItsPlace(): void
    {
        // The control for the cases above: a validator that accepts everything would pass them too.
        $schema = self::schema('FACTURX_XSD_DIR', 'Factur-X_EN16931.xsd');
        $document = new \DOMDocument();
        $document->loadXML(new CiiInvoiceXml()->write(CiiInvoiceXmlTest::standard()));
        $header = $document->getElementsByTagNameNS('urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100', 'ExchangedDocument')->item(0);
        self::assertNotNull($header);
        $typeCode = $header->childNodes->item(3);
        $id = $header->childNodes->item(1);
        self::assertNotNull($typeCode);
        self::assertSame(['ram:TypeCode', 'ram:ID'], [$typeCode->nodeName, $id?->nodeName]);
        $header->insertBefore($typeCode, $id);

        $errors = self::errors((string) $document->saveXML(), $schema);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('TypeCode', $errors[0]);
    }

    private static function schema(string $variable, ?string $file): string
    {
        $value = getenv($variable);
        if (!\is_string($value) || '' === $value) {
            self::markTestSkipped("$variable is not set: the published schema is not in this repository.");
        }
        $path = null === $file ? $value : rtrim($value, '/').'/'.$file;
        self::assertFileExists($path);

        return $path;
    }

    /** @return list<string> libxml's messages, none when the file is valid */
    private static function errors(string $xml, string $schema): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new \DOMDocument();
            self::assertTrue($document->loadXML($xml));
            $document->schemaValidate($schema);

            return array_map(static fn (\LibXMLError $error): string => trim($error->message).' (line '.$error->line.')', libxml_get_errors());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
