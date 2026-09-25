<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Expenses\Infrastructure\Tej;

use App\Module\Expenses\Application\Tej\TejCertificate;
use App\Module\Expenses\Application\Tej\TejDeclaration;
use App\Module\Expenses\Application\Tej\TejOperation;
use App\Module\Expenses\Domain\TejOperationCode;
use App\Module\Expenses\Infrastructure\Tej\TejDeclarationXml;
use PHPUnit\Framework\TestCase;

/**
 * The file the TEJ platform takes (cahier des charges TEJ, September 2026; `TEJDeclarationRS_v1.0.xsd`): its elements
 * in the schema's order, which is also where the schema and the cahier's own example disagree — the example has no
 * `TotalMontantTTC`, the schema requires it — and every amount a whole number of millimes.
 */
final class TejDeclarationXmlTest extends TestCase
{
    public function testTheFileReadsInTheSchemasOrderWithItsAmountsInMillimes(): void
    {
        $xml = (new TejDeclarationXml())->write(self::declaration());

        self::assertSame(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <DeclarationsRS VersionSchema="1.0">
              <Declarant>
                <TypeIdentifiant>1</TypeIdentifiant>
                <Identifiant>1234567A</Identifiant>
                <CategorieContribuable>PM</CategorieContribuable>
              </Declarant>
              <ReferenceDeclaration>
                <ActeDepot>0</ActeDepot>
                <AnneeDepot>2026</AnneeDepot>
                <MoisDepot>09</MoisDepot>
              </ReferenceDeclaration>
              <AjouterCertificats>
                <Certificat>
                  <Beneficiaire>
                    <IdTaxpayer>
                      <MatriculeFiscal>
                        <TypeIdentifiant>1</TypeIdentifiant>
                        <Identifiant>7654321B</Identifiant>
                        <CategorieContribuable>PP</CategorieContribuable>
                      </MatriculeFiscal>
                    </IdTaxpayer>
                    <Resident>1</Resident>
                    <NometprenonOuRaisonsociale>Matériel &amp; Gros &lt;Sud&gt;</NometprenonOuRaisonsociale>
                    <Adresse>4, rue de Marseille, 1000 Tunis</Adresse>
                    <InfosContact>
                      <AdresseMail>achats@sotumag.tn</AdresseMail>
                      <NumTel>+216 71 000 000</NumTel>
                    </InfosContact>
                  </Beneficiaire>
                  <DatePayement>03/09/2026</DatePayement>
                  <Ref_certif_chez_declarant>0199aa00-0000-7000-8000-000000000001</Ref_certif_chez_declarant>
                  <ListeOperations>
                    <Operation IdTypeOperation="RS7_000001">
                      <AnneeFacturation>2025</AnneeFacturation>
                      <CNPC>0</CNPC>
                      <P_Charge>0</P_Charge>
                      <MontantHT>1037451</MontantHT>
                      <TauxRS>1.5</TauxRS>
                      <TauxTVA>19</TauxTVA>
                      <MontantTVA>197116</MontantTVA>
                      <MontantTTC>1234567</MontantTTC>
                      <MontantRS>18519</MontantRS>
                      <MontantNetServi>1216048</MontantNetServi>
                    </Operation>
                  </ListeOperations>
                  <TotalPayement>
                    <TotalMontantHT>1037451</TotalMontantHT>
                    <TotalMontantTVA>197116</TotalMontantTVA>
                    <TotalMontantTTC>1234567</TotalMontantTTC>
                    <TotalMontantRS>18519</TotalMontantRS>
                    <TotalMontantNetServi>1216048</TotalMontantNetServi>
                  </TotalPayement>
                </Certificat>
                <Certificat>
                  <Beneficiaire>
                    <IdTaxpayer>
                      <MatriculeFiscal>
                        <TypeIdentifiant>1</TypeIdentifiant>
                        <Identifiant>7654321B</Identifiant>
                        <CategorieContribuable>PP</CategorieContribuable>
                      </MatriculeFiscal>
                    </IdTaxpayer>
                    <Resident>1</Resident>
                    <NometprenonOuRaisonsociale>Matériel &amp; Gros &lt;Sud&gt;</NometprenonOuRaisonsociale>
                    <Adresse>4, rue de Marseille, 1000 Tunis</Adresse>
                    <InfosContact>
                      <AdresseMail>achats@sotumag.tn</AdresseMail>
                      <NumTel>+216 71 000 000</NumTel>
                    </InfosContact>
                  </Beneficiaire>
                  <DatePayement>28/09/2026</DatePayement>
                  <Ref_certif_chez_declarant>0199aa00-0000-7000-8000-000000000002</Ref_certif_chez_declarant>
                  <ListeOperations>
                    <Operation IdTypeOperation="RS7_000006">
                      <AnneeFacturation>2026</AnneeFacturation>
                      <CNPC>0</CNPC>
                      <P_Charge>0</P_Charge>
                      <MontantHT>2000000</MontantHT>
                      <TauxRS>0</TauxRS>
                      <TauxTVA>0</TauxTVA>
                      <MontantTVA>0</MontantTVA>
                      <MontantTTC>2000000</MontantTTC>
                      <MontantRS>0</MontantRS>
                      <MontantNetServi>2000000</MontantNetServi>
                    </Operation>
                  </ListeOperations>
                  <TotalPayement>
                    <TotalMontantHT>2000000</TotalMontantHT>
                    <TotalMontantTVA>0</TotalMontantTVA>
                    <TotalMontantTTC>2000000</TotalMontantTTC>
                    <TotalMontantRS>0</TotalMontantRS>
                    <TotalMontantNetServi>2000000</TotalMontantNetServi>
                  </TotalPayement>
                </Certificat>
              </AjouterCertificats>
            </DeclarationsRS>

            XML, $xml);
        self::assertSame('1234567A-2026-09-0.xml', self::declaration()->fileName());
    }

    public function testACertificateTotalsItsOperations(): void
    {
        $certificate = self::certificate('2026-09-03', 1, [self::bought(), self::exempt()]);

        self::assertSame(['amountNet' => 3037451, 'vatAmount' => 197116, 'amountGross' => 3234567, 'withheld' => 18519, 'netPaid' => 3216048], $certificate->totals());
    }

    public static function declaration(): TejDeclaration
    {
        return new TejDeclaration('1234567A', 'PM', 2026, 9, [
            self::certificate('2026-09-03', 1, [self::bought()]),
            self::certificate('2026-09-28', 2, [self::exempt()]),
        ]);
    }

    /** @param non-empty-list<TejOperation> $operations */
    private static function certificate(string $paidOn, int $n, array $operations): TejCertificate
    {
        return new TejCertificate('7654321B', 'PP', 'Matériel & Gros <Sud>', '4, rue de Marseille, 1000 Tunis', 'achats@sotumag.tn', '+216 71 000 000', new \DateTimeImmutable($paidOn), \sprintf('0199aa00-0000-7000-8000-%012d', $n), $operations);
    }

    private static function bought(): TejOperation
    {
        return new TejOperation(TejOperationCode::Rs7_000001, 2025, 1037451, '1.5', '19', 197116, 1234567, 18519, 1216048);
    }

    private static function exempt(): TejOperation
    {
        return new TejOperation(TejOperationCode::Rs7_000006, 2026, 2000000, '0', '0', 0, 2000000, 0, 2000000);
    }
}
