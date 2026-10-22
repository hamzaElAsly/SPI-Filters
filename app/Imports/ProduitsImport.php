<?php

namespace App\Imports;

use App\Exceptions\ProduitsImportValidationException;
use App\Models\Produit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Throwable;

class ProduitsImport implements ToCollection, WithStartRow
{
    private Collection $rows;

    /** @var array<int, array{ref: string, description: string, prix_vente: string, prix_achat: string, image_url: ?string, line: int}> */
    private array $prepared = [];

    /** @var array<int, array{line: int, reason: string, type: string}> */
    private array $issues = [];

    /** @var array<int, true> */
    private array $invalidLines = [];

    private int $ignoredRows = 0;

    private int $duplicateIgnored = 0;

    public function collection(Collection $rows): void
    {
        $this->rows = $rows;
    }

    public function sheets(): array
    {
        return [0 => $this]; // première feuille uniquement
    }

    public function startRow(): int
    {
        // Row 1 is intentionally blank; row 2 contains the column headers.
        return 2;
    }

    public function validateAndPrepare(): void
    {
        $this->prepared = [];
        $this->issues = [];
        $this->invalidLines = [];
        $this->ignoredRows = 0;
        $this->duplicateIgnored = 0;

        if (! isset($this->rows) || $this->rows->isEmpty()) {
            $this->addIssue(2, 'Le fichier Excel est vide ou ne contient pas la ligne d’en-têtes.', 'structure');
            throw new ProduitsImportValidationException($this->issues);
        }

        $header = $this->rows->first();
        $headerIndexes = [];

        foreach ($this->values($header) as $index => $value) {
            // $normalized = strtoupper(preg_replace('/[^A-Z0-9]+/', '_', $this->cell($value)) ?? '');
            $normalized = trim((string) preg_replace('/[^A-Z0-9]+/', '_', Str::upper(Str::ascii($this->cell($value)))), '_');
            if ($normalized !== '') {
                $headerIndexes[$normalized] = $index;
            }
        }

        $imageIndex = null;
        foreach (['IMAGE', 'IMAGES', 'IMAGE_URL', 'URL_IMAGE', 'IMAGE_SOURCE', 'PHOTO'] as $imageHeader) {
            if (array_key_exists($imageHeader, $headerIndexes)) {
                $imageIndex = $headerIndexes[$imageHeader];
                break;
            }
        }

        $requiredHeaders = ['REF', 'PRIX', 'DESIGNATION'];
        $missingHeaders = array_values(array_diff($requiredHeaders, array_keys($headerIndexes)));
        if ($missingHeaders !== []) {
            $this->addIssue(2, 'Colonnes manquantes : ' . implode(', ', $missingHeaders) . '.', 'structure');
            throw new ProduitsImportValidationException($this->issues);
        }

        /** @var array<string, array<int, array{ref: string, description: string, prix_vente: ?string, prix_achat: string, line: int, errors: array<int, string>}>> $rowsByReference */
        $rowsByReference = [];
        $line = 2;

        foreach ($this->rows->skip(1) as $row) {
            $line++;
            $values = $this->values($row);

            if ($this->isBlankRow($values)) {
                $this->ignoredRows++;

                continue;
            }

            $ref = $this->cell($values[$headerIndexes['REF']] ?? null);
            $description = $this->cell($values[$headerIndexes['DESIGNATION']] ?? null);
            $price = $this->normalizePrice($values[$headerIndexes['PRIX']] ?? null);
            $imageUrl = $imageIndex === null ? null : $this->cell($values[$imageIndex] ?? null);
            $rowErrors = [];

            if ($ref === '') {
                $rowErrors[] = 'La référence est obligatoire.';
            } elseif (mb_strlen($ref) > 255) {
                $rowErrors[] = 'La référence ne doit pas dépasser 255 caractères.';
            }

            if ($description === '') {
                $rowErrors[] = 'La désignation est obligatoire.';
            }

            if ($price === null) {
                $rowErrors[] = 'Le prix doit être numérique et supérieur ou égal à zéro.';
            }

            $parsedRow = [
                'ref' => $ref,
                'description' => $description,
                'prix_vente' => $price,
                'prix_achat' => '0.00',
                'image_url' => $imageUrl !== '' ? $imageUrl : null,
                'line' => $line,
                'errors' => $rowErrors,
            ];

            if ($ref !== '') {
                $rowsByReference[$this->referenceKey($ref)][] = $parsedRow;
            } else {
                $this->addRowErrors($line, $rowErrors);
            }
        }

        foreach ($rowsByReference as $referenceRows) {
            $lastValidIndex = null;

            foreach ($referenceRows as $index => $row) {
                if ($row['errors'] === []) {
                    $lastValidIndex = $index;
                }
            }

            foreach ($referenceRows as $index => $row) {
                if ($row['errors'] !== []) {
                    $this->addRowErrors($row['line'], $row['errors']);
                }

                if ($lastValidIndex === null || $index === $lastValidIndex) {
                    continue;
                }

                if ($row['errors'] === []) {
                    $this->duplicateIgnored++;
                }
                $this->addIssue(
                    $row['line'],
                    'Référence dupliquée dans le fichier ; occurrence valide conservée à la ligne ' . $referenceRows[$lastValidIndex]['line'] . '.',
                    'duplicate'
                );
            }

            if ($lastValidIndex !== null) {
                $winner = $referenceRows[$lastValidIndex];
                $this->prepared[] = [
                    'ref' => $winner['ref'],
                    'description' => $winner['description'],
                    'prix_vente' => $winner['prix_vente'],
                    'prix_achat' => '0.00',
                    'image_url' => $winner['image_url'],
                    'line' => $winner['line'],
                ];
            }
        }

        if ($this->prepared === [] && $this->ignoredRows === 0 && $this->issues === []) {
            $this->addIssue(3, 'Le fichier ne contient aucune ligne de produit.', 'invalid');
            $this->invalidLines[3] = true;
        }

        if ($this->prepared !== []) {
            $this->validateRequiredFieldsForNewProducts();
        }
    }

    /**
     * @return array{created: int, updated: int, ignored: int, duplicates: int, invalid: int, issues: array<int, array{line: int, reason: string, type: string}>}
     */
    public function report(array $result = []): array
    {
        $issues = $this->issues;
        usort($issues, fn(array $left, array $right): int => [$left['line'], $left['type']] <=> [$right['line'], $right['type']]);

        return [
            'created' => (int) ($result['created'] ?? 0),
            'updated' => (int) ($result['updated'] ?? 0),
            'ignored' => $this->ignoredRows,
            'duplicates' => $this->duplicateIgnored,
            'invalid' => count($this->invalidLines),
            'issues' => $issues,
        ];
    }

    /**
     * @return array{created: int, updated: int, ignored: int, duplicates: int, invalid: int, issues: array<int, array{line: int, reason: string, type: string}>}
     */
    public function persist(): array
    {
        $references = array_column($this->prepared, 'ref');
        $existing = Produit::query()
            ->whereIn('ref', $references)
            ->lockForUpdate()
            ->get()
            ->keyBy(fn(Produit $produit) => $this->referenceKey($produit->ref));

        $created = 0;
        $updated = 0;

        foreach ($this->prepared as $row) {
            $key = $this->referenceKey($row['ref']);
            $product = $existing->get($key);
            $imagePath = $this->downloadImage($row['image_url'], $row['line'], $row['ref']);

            if ($product) {
                // Excel only supplies these two fields; preserve all other product data.
                $data = [
                    'prix_vente' => $row['prix_vente'],
                    'description' => $row['description'],
                ];
                if ($imagePath !== null) {
                    $oldImage = $product->image;
                    $data['image'] = $imagePath;
                    if ($oldImage) {
                        DB::afterCommit(fn() => Storage::disk('public')->delete($oldImage));
                    }
                } 
                $product->update($data);
                $updated++;
                continue;
            }

            $data = [
                'ref' => $row['ref'],
                'description' => $row['description'],
                'prix_vente' => $row['prix_vente'],
                'prix_achat' => $row['prix_achat'],
            ];
            if ($imagePath !== null) {
                $data['image'] = $imagePath;
            }
            $product = Produit::create($data);
            $existing->put($key, $product);
            $created++;
        }

        return $this->report([
            'created' => $created,
            'updated' => $updated,
        ]);
    }

    /**
     * @return array<int, array{line: int, reason: string, type: string}>
     */
    public function errors(): array
    {
        return $this->issues;
    }

    private function validateRequiredFieldsForNewProducts(): void
    {
        $references = array_column($this->prepared, 'ref');
        $existingKeys = Produit::query()
            ->whereIn('ref', $references)
            ->pluck('ref')
            ->mapWithKeys(fn(string $ref) => [$this->referenceKey($ref) => true]);

        $columns = collect(Schema::getColumns('produits'));
        $purchaseColumn = $columns->first(fn(array $column): bool => ($column['name'] ?? null) === 'prix_achat');
        $purchasePrice = $this->provisionalPurchasePrice($purchaseColumn);
        $requiredWithoutValue = $columns
            ->filter(function (array $column): bool {
                $name = strtolower((string) ($column['name'] ?? ''));
                $isAutomatic = (bool) ($column['auto_increment'] ?? false) || in_array($name, ['id', 'created_at', 'updated_at'], true);

                return ! $isAutomatic
                    && ($column['nullable'] ?? false) === false
                    && (($column['default'] ?? null) === null)
                // && ! in_array($name, ['ref', 'description', 'prix_vente', 'prix_achat'], true);
                    && ! in_array($name, ['ref', 'description', 'prix_vente', 'prix_achat', 'old_prix'], true);
            })
            ->pluck('name')
            ->values()
            ->all();

        foreach ($this->prepared as $index => $row) {
            if ($existingKeys->has($this->referenceKey($row['ref']))) {
                continue;
            }

            if ($purchasePrice === null) {
                $this->addRowErrors($row['line'], ['Impossible de créer la référence : le schéma n’autorise pas la valeur provisoire 0 pour prix_achat.']);
                unset($this->prepared[$index]);

                continue;
            }

            if ($requiredWithoutValue !== []) {
                $this->addRowErrors(
                    $row['line'],
                    ['Impossible de créer la référence : champs obligatoires sans valeur autorisée dans le fichier ou le schéma : ' . implode(', ', $requiredWithoutValue) . '.']
                );
                unset($this->prepared[$index]);

                continue;
            }

            $this->prepared[$index]['prix_achat'] = $purchasePrice;
        }

        $this->prepared = array_values($this->prepared);
    }

    /** @param array{name?: string, type?: string, type_name?: string}|null $column */
    private function provisionalPurchasePrice(?array $column): ?string
    {
        if ($column === null) {
            return null;
        }

        $type = strtolower((string) ($column['type'] ?? $column['type_name'] ?? ''));
        $numeric = str_contains($type, 'decimal')
            || str_contains($type, 'numeric')
            || str_contains($type, 'double')
            || str_contains($type, 'float')
            || str_contains($type, 'int');

        return $numeric ? '0.00' : null;
    }

    /** @return array<int, mixed> */
    private function values(mixed $row): array
    {
        return $row instanceof Collection ? array_values($row->all()) : array_values((array) $row);
    }

    private function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    /** @param array<int, mixed> $values */
    private function isBlankRow(array $values): bool
    {
        foreach ($values as $value) {
            if ($this->cell($value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizePrice(mixed $value): ?string
    {
        $price = $this->cell($value);
        if ($price === '') {
            return null;
        }

        $price = str_replace(["\u{00A0}", ' '], '', $price);
        $commaPosition = strrpos($price, ',');
        $dotPosition = strrpos($price, '.');

        if ($commaPosition !== false && $dotPosition !== false) {
            if ($commaPosition > $dotPosition) {
                $price = str_replace('.', '', $price);
                $price = str_replace(',', '.', $price);
            } else {
                $price = str_replace(',', '', $price);
            }
        } else {
            $price = str_replace(',', '.', $price);
        }

        if (! preg_match('/^\d+(?:\.\d+)?$/', $price) || ! is_finite((float) $price) || (float) $price < 0) {
            return null;
        }

        return number_format((float) $price, 2, '.', '');
    }

    private function referenceKey(string $ref): string
    {
        return mb_strtolower($ref);
    }

    /** @param array<int, string> $reasons */
    private function addRowErrors(int $line, array $reasons): void
    {
        if ($reasons === []) {
            return;
        }

        $this->invalidLines[$line] = true;
        foreach ($reasons as $reason) {
            $this->addIssue($line, $reason, 'invalid');
        }
    }

    private function addIssue(int $line, string $reason, string $type): void
    {
        $this->issues[] = ['line' => $line, 'reason' => $reason, 'type' => $type];
    }

    private function downloadImage(?string $source, int $line, string $reference): ?string
    {
        $source = trim((string) $source);
        if ($source === '') {
            return null;
        }

        $context = [
            'line' => $line,
            'reference' => $reference,
            'source_url' => $source,
            'disk' => 'public',
            'disk_root' => (string) config('filesystems.disks.public.root'),
            'public_link' => public_path('storage'),
        ];
        $parsedUrl = parse_url($source);
        $scheme = strtolower((string) ($parsedUrl['scheme'] ?? ''));
        if (! is_array($parsedUrl) || ! in_array($scheme, ['http', 'https'], true)) {
            $this->imageFailure($line, 'URL d’image distante invalide.', $context);

            return null;
        }

        try {
            $response = Http::accept('image/*')
                ->connectTimeout(10)
                ->timeout(30)
                ->retry(2, 250)
                ->get($source);
        } catch (Throwable $exception) {
            $this->imageFailure($line, 'Téléchargement de l’image impossible : ' . $exception->getMessage(), $context, $exception);

            return null;
        }

        if (! $response->successful()) {
            $this->imageFailure($line, 'Le serveur d’image a répondu avec le statut HTTP ' . $response->status() . '.', $context);

            return null;
        }

        $body = $response->body();
        if ($body === '') {
            $this->imageFailure($line, 'La réponse HTTP de l’image est vide.', $context);

            return null;
        }

        if (strlen($body) > 10 * 1024 * 1024) {
            $this->imageFailure($line, 'L’image distante dépasse la taille maximale de 10 Mo.', $context);

            return null;
        }

        $extension = $this->imageExtension($source, (string) $response->header('Content-Type'));
        if ($extension === null) {
            $this->imageFailure($line, 'Le type de contenu de l’image n’est pas accepté.', $context);

            return null;
        }

        $path = 'produits/' . Str::random(40) . '.' . $extension;
        try {
            if (! Storage::disk('public')->put($path, $body)) {
                $this->imageFailure($line, 'Le fichier image n’a pas pu être écrit sur le disque public.', [...$context, 'path' => $path]);

                return null;
            }
        } catch (Throwable $exception) {
            $this->imageFailure($line, 'Erreur lors de l’écriture du fichier image : ' . $exception->getMessage(), [...$context, 'path' => $path], $exception);

            return null;
        }

        Log::info('Image produit téléchargée pendant l’import.', [...$context, 'path' => $path]);

        return $path;
    }

    private function imageExtension(string $source, string $contentType): ?string
    {
        $mime = strtolower(trim((string) strtok($contentType, ';')));
        $mimeExtensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/avif' => 'avif',
        ];
        if (isset($mimeExtensions[$mime])) {
            return $mimeExtensions[$mime];
        }
        if ($mime !== '' && $mime !== 'application/octet-stream' && ! str_starts_with($mime, 'image/')) {
            return null;
        }

        $extension = strtolower((string) pathinfo((string) parse_url($source, PHP_URL_PATH), PATHINFO_EXTENSION));
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        return in_array($extension, ['jpg', 'png', 'webp', 'gif', 'avif'], true) ? $extension : null;
    }

    /** @param array<string, mixed> $context */
    private function imageFailure(int $line, string $reason, array $context, ?Throwable $exception = null): void
    {
        $this->addIssue($line, $reason, 'image');
        Log::warning('Image produit non téléchargée pendant l’import.', [
            ...$context,
            'reason' => $reason,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
