<?php

namespace App\Services;

use App\Models\Produit;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class ProduitExcelImportService
{
  private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

  private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

  private const NS_PKG_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

  private const EXPECTED_HEADERS = [
    'A' => 'REF',
    'B' => 'PRIX',
    'C' => 'DESIGNATION',
  ];

  /** Marque une cellule contenant une erreur Excel (#REF!, #N/A…). */
  private const ERROR_PREFIX = "\0EXCEL_ERROR:";

  /** Lignes où l’en-tête peut se trouver (ligne 2 attendue, ligne 1 tolérée). */
  private const HEADER_ROWS = [2, 1];

  /**
   * @return array{rows:int, imported:int, updated:int, skipped:int, errors:list<array{row:int, message:string}>}
   */
  public function import(
    string $filePath,
    bool $dryRun = false,
    string $duplicateMode = 'update',
    int $batchSize = 100,
  ): array {
    if (! in_array($duplicateMode, ['update', 'skip', 'error'], true)) {
      throw new RuntimeException('Le mode de doublon doit être update, skip ou error.');
    }

    if ($batchSize < 1) {
      throw new RuntimeException('La taille du lot doit être supérieure à zéro.');
    }

    $rows = $this->readRows($filePath);
    $headerRow = $this->findHeaderRow($rows);

    $summary = [
      'rows' => 0,
      'imported' => 0,
      'updated' => 0,
      'skipped' => 0,
      'errors' => [],
    ];
    $batch = [];
    $plannedRefs = [];

    foreach ($rows as $rowNumber => $cells) {
      if ($rowNumber <= $headerRow || $this->isEmptyRow($cells)) {
        continue;
      }

      $summary['rows']++;
      try {
        $this->assertNoExcelError($cells);
        $batch[] = [
          'row' => $rowNumber,
          'ref' => $this->parseReference($cells['A'] ?? ''),
          'prix_vente' => $this->parsePrice($cells['B'] ?? ''),
          'description' => $this->parseDescription($cells['C'] ?? ''),
        ];
      } catch (RuntimeException $exception) {
        $summary['errors'][] = [
          'row' => $rowNumber,
          'message' => $exception->getMessage(),
        ];
      }

      if (count($batch) >= $batchSize) {
        $this->processBatch($batch, $summary, $dryRun, $duplicateMode, $plannedRefs);
        $batch = [];
      }
    }

    if ($batch !== []) {
      $this->processBatch($batch, $summary, $dryRun, $duplicateMode, $plannedRefs);
    }

    if ($summary['rows'] === 0) {
      throw new RuntimeException('Le fichier ne contient aucune ligne de produit.');
    }

    return $summary;
  }

  /**
   * @param  list<array{row:int, ref:string, prix_vente:string, description:string}>  $batch
   * @param  array{rows:int, imported:int, updated:int, skipped:int, errors:list<array{row:int, message:string}>}  $summary
   * @param  array<string, bool>  $plannedRefs
   */
  private function processBatch(
    array $batch,
    array &$summary,
    bool $dryRun,
    string $duplicateMode,
    array &$plannedRefs,
  ): void {
    // Une seule requête pour tout le lot au lieu d’une requête par ligne.
    $existing = Produit::query()
      ->whereIn('ref', array_column($batch, 'ref'))
      ->get()
      ->keyBy(fn(Produit $produit): string => $this->referenceKey((string) $produit->ref));

    $process = function () use ($batch, &$summary, $dryRun, $duplicateMode, &$plannedRefs, $existing): void {
      foreach ($batch as $data) {
        $key = $this->referenceKey($data['ref']);
        $produit = $existing->get($key);
        $exists = $produit !== null || isset($plannedRefs[$key]);

        if ($exists && $duplicateMode === 'skip') {
          $summary['skipped']++;
          $plannedRefs[$key] = true;

          continue;
        }

        if ($exists && $duplicateMode === 'error') {
          $summary['errors'][] = [
            'row' => $data['row'],
            'message' => 'La référence existe déjà en base ou dans ce fichier.',
          ];

          continue;
        }

        $summary[$exists ? 'updated' : 'imported']++;
        $plannedRefs[$key] = true;

        if ($dryRun) {
          continue;
        }

        if ($produit === null) {
          // Nouveau produit : prix d’achat provisoire à 0.
          $produit = new Produit;
          $produit->fill([
            'ref' => $data['ref'],
            'prix_achat' => '0.00',
          ]);
        }

        // Mise à jour : seuls les champs du fichier sont modifiés
        // (prix_achat, image, catégorie et WooCommerce sont conservés).
        $produit->fill([
          'description' => $data['description'],
          'prix_vente' => $data['prix_vente'],
        ]);
        $produit->saveOrFail();
        $existing->put($key, $produit);
      }
    };

    if ($dryRun) {
      $process();

      return;
    }

    DB::transaction($process);
  }

  /** @param array<string, string> $cells */
  private function assertNoExcelError(array $cells): void
  {
    foreach ($cells as $column => $value) {
      if (str_starts_with($value, self::ERROR_PREFIX)) {
        throw new RuntimeException(sprintf(
          'La colonne %s contient une erreur Excel (%s).',
          $column,
          substr($value, strlen(self::ERROR_PREFIX)),
        ));
      }
    }
  }

  /** @param array<int, array<string, string>> $rows */
  private function findHeaderRow(array $rows): int
  {
    foreach (self::HEADER_ROWS as $rowNumber) {
      if ($this->isHeaderRow($rows[$rowNumber] ?? [])) {
        return $rowNumber;
      }
    }

    $cells = $rows[2] ?? [];
    throw new RuntimeException(sprintf(
      'En-têtes invalides en ligne 2 : attendu « REF | PRIX | DESIGNATION », reçu « %s | %s | %s ».',
      $cells['A'] ?? '',
      $cells['B'] ?? '',
      $cells['C'] ?? '',
    ));
  }

  /** @param array<string, string> $cells */
  private function isHeaderRow(array $cells): bool
  {
    foreach (self::EXPECTED_HEADERS as $column => $expected) {
      if ($this->normalizeHeader($cells[$column] ?? '') !== $expected) {
        return false;
      }
    }

    return true;
  }

  private function parseReference(string $value): string
  {
    $reference = trim(str_replace("\xC2\xA0", ' ', $value));
    if ($reference === '') {
      throw new RuntimeException('La référence est obligatoire.');
    }

    if (mb_strlen($reference) > 255) {
      throw new RuntimeException('La référence ne doit pas dépasser 255 caractères.');
    }

    return $reference;
  }

  private function parseDescription(string $value): string
  {
    $description = trim(str_replace("\xC2\xA0", ' ', $value));

    return $description === '' ? 'Filter' : $description;
  }

  private function parsePrice(string $value): string
  {
    $price = trim(str_replace(["\xC2\xA0", "\xE2\x80\xAF", ' '], '', $value));
    $price = preg_replace('/(DH|MAD|DHS|€)$/i', '', $price) ?? $price;

    if ($price === '') {
      throw new RuntimeException('Le prix est obligatoire.');
    }

    // Valeur numérique brute d’Excel (ex. 19.989999999999998 ou 1.5E+3).
    if (preg_match('/^\d+(?:\.\d+)?(?:E[+-]?\d+)?$/i', $price)) {
      return $this->formatPrice((float) $price, $value);
    }

    // Saisie texte au format français ou anglais.
    if (str_contains($price, ',') && str_contains($price, '.')) {
      if (strrpos($price, ',') > strrpos($price, '.')) {
        $price = str_replace('.', '', $price);
        $price = str_replace(',', '.', $price);
      } else {
        $price = str_replace(',', '', $price);
      }
    } else {
      $price = str_replace(',', '.', $price);
    }

    if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $price)) {
      throw new RuntimeException(sprintf('Prix invalide : « %s ».', $value));
    }

    return $this->formatPrice((float) $price, $value);
  }

  private function formatPrice(float $price, string $original): string
  {
    if (! is_finite($price) || $price < 0 || $price > 99999999.99) {
      throw new RuntimeException(sprintf('Prix hors limites : « %s ».', $original));
    }

    return number_format(round($price, 2), 2, '.', '');
  }

  /** @param array<string, string> $cells */
  private function isEmptyRow(array $cells): bool
  {
    foreach (array_keys(self::EXPECTED_HEADERS) as $column) {
      if (trim(str_replace("\xC2\xA0", ' ', $cells[$column] ?? '')) !== '') {
        return false;
      }
    }

    return true;
  }

  private function normalizeHeader(string $value): string
  {
    $value = mb_strtoupper(trim(str_replace("\xC2\xA0", ' ', $value)));
    $value = strtr($value, [
      'É' => 'E',
      'È' => 'E',
      'Ê' => 'E',
      'Ë' => 'E',
      'À' => 'A',
      'Â' => 'A',
      'Ä' => 'A',
      'Î' => 'I',
      'Ï' => 'I',
      'Ô' => 'O',
      'Ö' => 'O',
      'Ù' => 'U',
      'Û' => 'U',
      'Ü' => 'U',
      'Ç' => 'C',
    ]);

    // « Désignation », « PRIX (DH) », « Réf. » → DESIGNATION, PRIX, REF
    $value = (string) preg_replace('/\s*\(.*\)$/', '', $value);
    $value = (string) preg_replace('/[^A-Z0-9]/', '', $value);

    return $value;
  }

  private function referenceKey(string $reference): string
  {
    return mb_strtolower(trim($reference));
  }

  /** @return array<int, array<string, string>> */
  private function readRows(string $filePath): array
  {
    $realPath = realpath($filePath);
    if ($realPath === false || ! is_readable($realPath)) {
      throw new RuntimeException('Le fichier Excel est introuvable ou illisible.');
    }

    if (strtolower(pathinfo($realPath, PATHINFO_EXTENSION)) !== 'xlsx') {
      throw new RuntimeException('Le fichier doit être au format .xlsx.');
    }

    if (! class_exists(ZipArchive::class)) {
      throw new RuntimeException('L’extension PHP ZipArchive est requise pour lire les fichiers .xlsx.');
    }

    $zip = new ZipArchive;
    if ($zip->open($realPath) !== true) {
      throw new RuntimeException('Le fichier Excel ne peut pas être ouvert (fichier corrompu ou protégé par mot de passe).');
    }

    try {
      $sheetXml = $zip->getFromName($this->firstSheetPath($zip));
      if ($sheetXml === false) {
        throw new RuntimeException('La première feuille Excel est introuvable.');
      }

      $sharedStrings = $this->readSharedStrings($zip);
      $sheet = $this->loadXml($sheetXml, 'La feuille Excel est invalide.');

      $rows = [];
      $nextRow = 1;
      foreach ($this->xpath($sheet, '//main:sheetData/main:row') as $row) {
        // L’attribut r est optionnel : on compte les lignes s’il est absent.
        $rowNumber = isset($row['r']) ? (int) $row['r'] : $nextRow;
        $nextRow = $rowNumber + 1;
        if ($rowNumber < 1) {
          continue;
        }

        $cells = [];
        $nextColumn = 1;
        foreach ($this->xpath($row, './main:c') as $cell) {
          $reference = (string) ($cell['r'] ?? '');
          $columnIndex = $reference !== ''
            ? $this->columnIndex($reference)
            : $nextColumn;
          $nextColumn = $columnIndex + 1;

          $column = $this->columnLetter($columnIndex);
          if (! array_key_exists($column, self::EXPECTED_HEADERS)) {
            continue;
          }

          $cells[$column] = $this->cellValue($cell, $sharedStrings);
        }

        $rows[$rowNumber] = $cells;
      }

      return $rows;
    } finally {
      $zip->close();
    }
  }

  /**
   * Trouve le fichier XML de la première feuille via workbook.xml,
   * car elle ne s’appelle pas toujours sheet1.xml.
   */
  private function firstSheetPath(ZipArchive $zip): string
  {
    $default = 'xl/worksheets/sheet1.xml';

    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relsXml === false) {
      return $default;
    }

    $workbook = $this->loadXml($workbookXml, 'Le classeur Excel est invalide.');
    $firstSheet = $this->xpath($workbook, '//main:sheets/main:sheet')[0] ?? null;
    if ($firstSheet === null) {
      return $default;
    }

    $relationId = (string) ($firstSheet->attributes(self::NS_REL)['id'] ?? '');
    if ($relationId === '') {
      return $default;
    }

    $rels = $this->loadXml($relsXml, 'Les relations du classeur Excel sont invalides.');
    $rels->registerXPathNamespace('rel', self::NS_PKG_REL);
    foreach ($rels->xpath('//rel:Relationship') ?: [] as $relationship) {
      if ((string) $relationship['Id'] !== $relationId) {
        continue;
      }

      $target = ltrim(str_replace('\\', '/', (string) $relationship['Target']), '/');

      return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
    }

    return $default;
  }

  /** @param list<string> $sharedStrings */
  private function cellValue(SimpleXMLElement $cell, array $sharedStrings): string
  {
    $type = (string) ($cell['t'] ?? '');
    $raw = (string) ($this->xpath($cell, './main:v')[0] ?? '');

    return match ($type) {
      's' => $sharedStrings[(int) $raw] ?? '',
      'inlineStr' => $this->richText($this->xpath($cell, './main:is')[0] ?? null),
      'b' => $raw === '1' ? '1' : '0',
      'e' => self::ERROR_PREFIX . $raw,
      default => $raw, // n (nombre), str (formule texte) ou vide
    };
  }

  /** @return list<string> */
  private function readSharedStrings(ZipArchive $zip): array
  {
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) {
      return [];
    }

    $shared = $this->loadXml($xml, 'Les chaînes partagées Excel sont invalides.');

    $values = [];
    foreach ($this->xpath($shared, '//main:si') as $item) {
      $values[] = $this->richText($item);
    }

    return $values;
  }

  /**
   * Texte d’un élément <si> ou <is> : texte simple (<t>) ou riche (<r><t>).
   * Les annotations phonétiques (<rPh>) sont ignorées.
   */
  private function richText(?SimpleXMLElement $element): string
  {
    if ($element === null) {
      return '';
    }

    $parts = $this->xpath($element, './main:t | ./main:r/main:t');

    return implode('', array_map(static fn(SimpleXMLElement $part): string => (string) $part, $parts));
  }

  /**
   * XPath avec le namespace « main » enregistré sur l’élément interrogé.
   * SimpleXML n’hérite pas des namespaces enregistrés sur le parent :
   * c’est la cause de l’erreur « Undefined namespace prefix ».
   *
   * @return list<SimpleXMLElement>
   */
  private function xpath(SimpleXMLElement $element, string $query): array
  {
    $element->registerXPathNamespace('main', self::NS_MAIN);

    return $element->xpath($query) ?: [];
  }

  private function loadXml(string $xml, string $errorMessage): SimpleXMLElement
  {
    $previous = libxml_use_internal_errors(true);

    try {
      $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
    } finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }

    if (! $document instanceof SimpleXMLElement) {
      throw new RuntimeException($errorMessage);
    }

    return $document;
  }

  private function columnIndex(string $cellReference): int
  {
    $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $cellReference));
    $index = 0;
    foreach (str_split($letters) as $letter) {
      $index = $index * 26 + (ord($letter) - 64);
    }

    return max(1, $index);
  }

  private function columnLetter(int $index): string
  {
    $letters = '';
    while ($index > 0) {
      $index--;
      $letters = chr(65 + ($index % 26)) . $letters;
      $index = intdiv($index, 26);
    }

    return $letters;
  }
}
