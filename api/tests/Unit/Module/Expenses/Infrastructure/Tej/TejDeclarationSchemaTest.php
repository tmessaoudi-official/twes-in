<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Expenses\Infrastructure\Tej;

use App\Module\Expenses\Domain\TejOperationCode;
use App\Module\Expenses\Infrastructure\Tej\TejDeclarationXml;
use PHPUnit\Framework\TestCase;

/**
 * The written file against the administration's own schema, `TEJDeclarationRS_v1.0.xsd` and the two files it
 * includes. The schema is not in the tree (its licence is unchecked), so this reads it from the directory
 * `TEJ_XSD_DIR` names and is skipped without it. Its `TEJISOPaysDevises.xsd` as published does not parse (a stray
 * `</xs:enumeration>` at line 1580); the directory holds a copy with that one line removed. The schema says
 * `vc:minVersion="1.1"` but uses only XSD 1.0 constructs, which libxml validates.
 */
final class TejDeclarationSchemaTest extends TestCase
{
    public function testTheWrittenFileIsValidAgainstTheAdministrationsSchema(): void
    {
        $document = $this->document((new TejDeclarationXml())->write(TejDeclarationXmlTest::declaration()));

        self::assertSame([], $this->violations($document));
    }

    public function testTheSchemaCheckRefusesAFileOutOfOrderSoItsPassMeansSomething(): void
    {
        $document = $this->document((new TejDeclarationXml())->write(TejDeclarationXmlTest::declaration()));
        $operation = $document->getElementsByTagName('Operation')->item(0);
        $rate = $document->getElementsByTagName('TauxRS')->item(0);
        self::assertNotNull($operation);
        self::assertNotNull($rate);
        $operation->insertBefore($rate, $operation->firstChild);

        self::assertNotSame([], $this->violations($document));
    }

    public function testTheOperationCodesAreExactlyTheSchemasList(): void
    {
        $schema = new \DOMDocument();
        self::assertTrue($schema->load($this->schemaDirectory().'/TEJRSCodesOperations_v1.0.xsd'));
        $xpath = new \DOMXPath($schema);
        $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
        $published = [];
        foreach ($xpath->query('//xs:simpleType[@name="TypeCodesOperations"]/xs:restriction/xs:enumeration') ?: [] as $enumeration) {
            self::assertInstanceOf(\DOMElement::class, $enumeration);
            $published[$enumeration->getAttribute('value')] = trim((string) preg_replace('/\s+/u', ' ', $enumeration->textContent));
        }

        self::assertCount(47, $published);
        self::assertSame($published, array_combine(array_map(static fn (TejOperationCode $code): string => $code->value, TejOperationCode::cases()), array_map(static fn (TejOperationCode $code): string => $code->label(), TejOperationCode::cases())));
    }

    private function document(string $xml): \DOMDocument
    {
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml));

        return $document;
    }

    /** @return list<string> */
    private function violations(\DOMDocument $document): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document->schemaValidate($this->schemaDirectory().'/TEJDeclarationRS_v1.0.xsd');

            return array_map(static fn (\LibXMLError $error): string => \sprintf('%s (line %d)', trim($error->message), $error->line), libxml_get_errors());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function schemaDirectory(): string
    {
        $directory = (string) getenv('TEJ_XSD_DIR');
        if ('' === $directory || !is_file($directory.'/TEJDeclarationRS_v1.0.xsd')) {
            self::markTestSkipped('TEJ_XSD_DIR names no directory holding TEJDeclarationRS_v1.0.xsd and the two files it includes (TEJISOPaysDevises.xsd patched at line 1580); the schema is not in the tree.');
        }

        return $directory;
    }
}
