<?php

namespace Tests\Feature;

use App\Models\Produit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class ImportProduitsExcelTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_dry_run_parses_french_prices_and_defaults_empty_designations(): void
    {
        $file = $this->workbook([
            1 => [],
            2 => ['REF', 'PRIX', 'DESIGNATION'],
            3 => ['001234', '300,00', 'F HUIL IVECO NV'],
            4 => ['5801516883', '250,00', '   '],
            5 => [],
        ]);

        $this->artisan('produits:import-excel', [
            'file' => $file,
            '--dry-run' => true,
        ])
            ->expectsOutput('Simulation terminée : aucune donnée n’a été écrite.')
            ->expectsOutput('Lignes de données : 2')
            ->expectsOutput('Produits importés : 2')
            ->expectsOutput('Erreurs : 0')
            ->assertExitCode(0);

        $this->assertDatabaseCount('produits', 0);
    }

    public function test_import_creates_products_and_calculates_old_price(): void
    {
        $file = $this->workbook([
            2 => ['REF', 'PRIX', 'DESIGNATION'],
            3 => ['001234', '300,00', 'F HUIL IVECO NV'],
            4 => ['REF_TEST', '100', ''],
        ]);

        $this->artisan('produits:import-excel', ['file' => $file])
            ->expectsOutput('Import terminé.')
            ->expectsOutput('Produits importés : 2')
            ->assertExitCode(0);

        $this->assertDatabaseHas('produits', [
            'ref' => '001234',
            'description' => 'F HUIL IVECO NV',
            'prix_achat' => '0.00',
            'prix_vente' => '300.00',
            'old_prix' => '405.00',
        ]);
        $this->assertDatabaseHas('produits', [
            'ref' => 'REF_TEST',
            'description' => 'Filter',
            'old_prix' => '135.00',
        ]);
    }

    public function test_existing_reference_is_updated_without_losing_existing_fields(): void
    {
        $product = Produit::create([
            'ref' => 'REF-EXISTANT',
            'description' => 'Ancienne désignation',
            'prix_achat' => 12,
            'prix_vente' => 50,
            'image' => 'produits/existante.jpg',
            'categorie_id' => null,
            'woocommerce_id' => 42,
        ]);

        $file = $this->workbook([
            2 => ['REF', 'PRIX', 'DESIGNATION'],
            3 => ['REF-EXISTANT', '80,00', 'Nouvelle désignation'],
        ]);

        $this->artisan('produits:import-excel', ['file' => $file])
            ->expectsOutput('Produits mis à jour : 1')
            ->assertExitCode(0);

        $product = $product->fresh();
        $this->assertSame('Nouvelle désignation', $product->description);
        $this->assertSame('0.00', (string) $product->prix_achat);
        $this->assertSame('80.00', (string) $product->prix_vente);
        $this->assertSame('108.00', (string) $product->old_prix);
        $this->assertSame('produits/existante.jpg', $product->image);
        $this->assertSame(42, $product->woocommerce_id);
    }

    public function test_duplicate_can_be_skipped_explicitly(): void
    {
        $product = Produit::create([
            'ref' => 'REF-SKIP',
            'description' => 'À conserver',
            'prix_achat' => 10,
            'prix_vente' => 20,
        ]);

        $file = $this->workbook([
            2 => ['REF', 'PRIX', 'DESIGNATION'],
            3 => ['REF-SKIP', '99,00', 'Ne pas remplacer'],
        ]);

        $this->artisan('produits:import-excel', [
            'file' => $file,
            '--duplicate' => 'skip',
        ])
            ->expectsOutput('Lignes ignorées : 1')
            ->assertExitCode(0);

        $product = $product->fresh();
        $this->assertSame('À conserver', $product->description);
        $this->assertSame('20.00', (string) $product->prix_vente);
    }

    public function test_invalid_price_is_reported_and_empty_rows_are_ignored(): void
    {
        $file = $this->workbook([
            2 => ['REF', 'PRIX', 'DESIGNATION'],
            3 => ['REF-VALID', '10,50', 'Valide'],
            4 => ['REF-INVALID', 'abc', 'Invalide'],
            5 => [],
        ]);

        $this->artisan('produits:import-excel', ['file' => $file])
            ->expectsOutput('Lignes de données : 2')
            ->expectsOutput('Erreurs : 1')
            ->expectsOutputToContain('Ligne 4 : Prix invalide')
            ->assertExitCode(1);

        $this->assertDatabaseHas('produits', ['ref' => 'REF-VALID', 'old_prix' => '14.18']);
        $this->assertDatabaseMissing('produits', ['ref' => 'REF-INVALID']);
    }

    /** @param array<int, array<int, string>> $rows */
    private function workbook(array $rows): string
    {
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $temporaryBase = tempnam($directory, 'produits-');
        $this->assertNotFalse($temporaryBase);
        unlink($temporaryBase);
        $path = $temporaryBase.'.xlsx';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $xmlRows = [];
        foreach ($rows as $rowNumber => $values) {
            $cells = [];
            foreach (array_values($values) as $index => $value) {
                if ($value === null) {
                    continue;
                }

                $column = chr(ord('A') + $index);
                $escaped = htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
                $cells[] = '<c r="'.$column.$rowNumber.'" t="inlineStr"><is><t>'.$escaped.'</t></is></c>';
            }
            $xmlRows[] = '<row r="'.$rowNumber.'">'.implode('', $cells).'</row>';
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.implode('', $xmlRows).'</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook/>');
        $zip->close();

        $this->temporaryFiles[] = $path;

        return $path;
    }
}
