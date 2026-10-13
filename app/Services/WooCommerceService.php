<?php

namespace App\Services;

use App\Exceptions\WooCommerceException;
use App\Models\Categorie;
use App\Models\Produit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class WooCommerceService
{
    private ?string $lastSyncWarning = null;

    public function lastSyncWarning(): ?string
    {
        return $this->lastSyncWarning;
    }

    public function testConnection(): array
    {
        try {
            $this->request('GET', 'products', ['per_page' => 1]);

            return ['success' => true, 'message' => 'Connexion à WooCommerce établie.'];
        } catch (WooCommerceException $exception) {
            return ['success' => false, 'message' => $exception->getMessage(), 'status' => $exception->statusCode()];
        }
    }

    public function findProductBySku(string $sku): ?array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        $products = $this->request('GET', 'products', ['sku' => $sku, 'per_page' => 10]);

        foreach ($products as $product) {
            if (is_array($product) && (string) ($product['sku'] ?? '') === $sku) {
                return $product;
            }
        }

        return $products[0] ?? null;
    }

    public function createCategory(Categorie $categorie): array
    {
        try {
            $data = $this->request('POST', 'products/categories', [], ['name' => $categorie->nom]);
            $this->saveCategoryWooCommerceId($categorie, $data);
            Log::info('WooCommerce category sync success', ['category_id' => $categorie->id, 'woocommerce_id' => $categorie->woocommerce_id]);

            return $data;
        } catch (WooCommerceException $exception) {
            $this->logCategoryFailure($categorie, $exception);
            throw $exception;
        }
    }

    public function updateCategory(Categorie $categorie): array
    {
        if (!$categorie->woocommerce_id) {
            return $this->syncCategory($categorie);
        }

        try {
            $data = $this->request('PUT', 'products/categories/'.$categorie->woocommerce_id, [], ['name' => $categorie->nom]);
            $this->saveCategoryWooCommerceId($categorie, $data);
            Log::info('WooCommerce category sync success', ['category_id' => $categorie->id, 'woocommerce_id' => $categorie->woocommerce_id]);

            return $data;
        } catch (WooCommerceException $exception) {
            $this->logCategoryFailure($categorie, $exception);
            throw $exception;
        }
    }

    public function syncCategory(Categorie $categorie): array
    {
        try {
            if ($categorie->woocommerce_id) {
                try {
                    return $this->updateCategory($categorie);
                } catch (WooCommerceException $exception) {
                    if ($exception->statusCode() !== 404) {
                        throw $exception;
                    }
                }
            }

            $existing = $this->findCategoryByName($categorie->nom);
            if ($existing !== null) {
                $this->saveCategoryWooCommerceId($categorie, $existing);
                Log::info('WooCommerce category sync success', ['category_id' => $categorie->id, 'woocommerce_id' => $categorie->woocommerce_id]);

                return $existing;
            }

            return $this->createCategory($categorie);
        } catch (WooCommerceException $exception) {
            $this->logCategoryFailure($categorie, $exception);
            throw $exception;
        }
    }

    public function createProduct(Produit $produit): array
    {
        $this->logProductStart($produit);

        try {
            $data = $this->productRequest('POST', 'products', $produit);
            $this->saveProductWooCommerceId($produit, $data);
            $this->logProductSuccess($produit);

            return $data;
        } catch (WooCommerceException $exception) {
            $this->logProductFailure($produit, $exception);
            throw $exception;
        }
    }

    public function updateProduct(Produit $produit): array
    {
        if (!$produit->woocommerce_id) {
            return $this->syncProduct($produit);
        }

        $this->logProductStart($produit);

        try {
            $data = $this->productRequest('PUT', 'products/'.$produit->woocommerce_id, $produit);
            $this->saveProductWooCommerceId($produit, $data);
            $this->logProductSuccess($produit);

            return $data;
        } catch (WooCommerceException $exception) {
            $this->logProductFailure($produit, $exception);
            throw $exception;
        }
    }

    public function syncProduct(Produit $produit): array
    {
        $this->lastSyncWarning = null;
        $this->logProductStart($produit);

        try {
            if ($produit->woocommerce_id) {
                try {
                    return $this->updateProduct($produit);
                } catch (WooCommerceException $exception) {
                    if ($exception->statusCode() !== 404) {
                        throw $exception;
                    }

                    $produit->forceFill(['woocommerce_id' => null, 'woocommerce_synced_at' => null])->save();
                }
            }

            $existing = $this->findProductBySku($produit->ref);
            if ($existing !== null && !empty($existing['id'])) {
                $produit->forceFill(['woocommerce_id' => (int) $existing['id']])->save();
                return $this->updateProduct($produit);
            }

            return $this->createProduct($produit);
        } catch (WooCommerceException $exception) {
            $this->logProductFailure($produit, $exception);
            throw $exception;
        }
    }

    private function productRequest(string $method, string $path, Produit $produit): array
    {
        $payload = $this->productPayload($produit, true);

        try {
            return $this->request($method, $path, [], $payload);
        } catch (WooCommerceException $exception) {
            if (!$exception->imageError || !array_key_exists('images', $payload)) {
                throw $exception;
            }

            unset($payload['images']);
            $this->lastSyncWarning = 'Le produit a été synchronisé, mais l’image n’a pas pu être envoyée.';

            return $this->request($method, $path, [], $payload);
        }
    }

    /** @return array<string, mixed> */
    private function productPayload(Produit $produit, bool $syncCategory): array
    {
        $produit->loadMissing('categorie');
        if ($syncCategory && $produit->categorie) {
            $this->syncCategory($produit->categorie);
        }

        $payload = [
            'type' => 'simple',
            'status' => 'publish',
            'sku' => (string) $produit->ref,
            'name' => $this->productName((string) $produit->description, (string) $produit->ref),
            'description' => (string) $produit->description,
            'regular_price' => number_format((float) $produit->prix_vente, 2, '.', ''),
        ];

        if ($produit->categorie?->woocommerce_id) {
            $payload['categories'] = [['id' => (int) $produit->categorie->woocommerce_id]];
        }

        $imageUrl = $this->getProductImageUrl($produit);
        if ($imageUrl !== null) {
            $payload['images'] = [['src' => $imageUrl]];
        }

        return $payload;
    }

    private function productName(string $description, string $ref): string
    {
        $description = trim($description);

        return Str::limit($description !== '' ? $description : $ref, 120, '');
    }

    public function getProductImageUrl(Produit $produit): ?string
    {
        if (!$produit->image) {
            return null;
        }

        if (Str::startsWith($produit->image, ['http://', 'https://'])) {
            $url = $produit->image;
        } else {
            if (!Storage::disk('public')->exists($produit->image)) {
                $this->lastSyncWarning = 'Le produit a été synchronisé, mais l’image n’a pas pu être envoyée.';

                return null;
            }

            $appUrl = rtrim((string) config('app.url'), '/');
            $url = $appUrl.'/storage/'.ltrim($produit->image, '/');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL) || $this->isLocalUrl($url)) {
            $this->lastSyncWarning = 'Le produit a été synchronisé, mais l’image n’a pas pu être envoyée.';

            return null;
        }

        return $url;
    }

    private function isLocalUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.local');
    }

    private function findCategoryByName(string $name): ?array
    {
        $categories = $this->request('GET', 'products/categories', ['search' => $name, 'per_page' => 100]);
        foreach ($categories as $category) {
            if (is_array($category) && strcasecmp((string) ($category['name'] ?? ''), $name) === 0) {
                return $category;
            }
        }

        return null;
    }

    private function saveCategoryWooCommerceId(Categorie $categorie, array $data): void
    {
        if (!empty($data['id'])) {
            $categorie->forceFill(['woocommerce_id' => (int) $data['id']])->save();
        }
    }

    private function saveProductWooCommerceId(Produit $produit, array $data): void
    {
        if (empty($data['id'])) {
            throw new WooCommerceException('La réponse WooCommerce est invalide.');
        }

        $produit->forceFill([
            'woocommerce_id' => (int) $data['id'],
            'woocommerce_synced_at' => now(),
        ])->save();
    }

    private function client(): PendingRequest
    {
        $key = (string) config('services.woocommerce.consumer_key');
        $secret = (string) config('services.woocommerce.consumer_secret');

        return Http::withBasicAuth($key, $secret)
            ->acceptJson()
            ->connectTimeout((int) config('services.woocommerce.connect_timeout', 10))
            ->timeout((int) config('services.woocommerce.timeout', 20))
            ->retry((int) config('services.woocommerce.retry_times', 1), 200, fn (Throwable $exception) => $exception instanceof ConnectionException, throw: false);
    }

    private function request(string $method, string $path, array $query = [], ?array $payload = null): array
    {
        try {
            $url = $this->baseUrl().'/'.ltrim($path, '/');
            $options = [];
            if ($query !== []) {
                $options['query'] = $query;
            }
            if ($payload !== null) {
                $options['json'] = $payload;
            }

            $response = $this->client()->send(strtoupper($method), $url, $options);
            if (!$response->successful()) {
                throw $this->responseException($response, $path);
            }

            $data = $response->json();

            return is_array($data) ? $data : [];
        } catch (WooCommerceException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('WooCommerce request failed', [
                'endpoint' => $path,
                'exception' => $exception::class,
            ]);

            throw new WooCommerceException('Impossible de se connecter à WooCommerce.', null, $exception);
        }
    }

    private function baseUrl(): string
    {
        $base = trim((string) config('services.woocommerce.url'));
        if ($base === '' || !filter_var($base, FILTER_VALIDATE_URL)) {
            throw new WooCommerceException('Impossible de se connecter à WooCommerce.');
        }

        return rtrim($base, '/').'/wp-json/wc/v3';
    }

    private function responseException(Response $response, string $path): WooCommerceException
    {
        $apiCode = strtolower((string) $response->json('code', ''));
        $apiMessage = strtolower((string) $response->json('message', ''));
        $imageError = str_contains($apiCode, 'image')
            || str_contains($apiCode, 'media')
            || str_contains($apiMessage, 'image')
            || str_contains($apiMessage, 'media')
            || str_contains($apiMessage, 'thumbnail')
            || str_contains($apiMessage, 'download')
            || str_contains($apiMessage, 'invalid url')
            || str_contains($apiMessage, 'ssl')
            || str_contains($apiMessage, 'format')
            || str_contains($apiMessage, 'file size');
        $message = match (true) {
            $response->status() === 401 => 'Authentification WooCommerce refusée.',
            $response->status() === 403 => 'Accès à WooCommerce refusé.',
            $response->status() === 404 => 'La ressource WooCommerce est introuvable.',
            str_contains($apiCode, 'sku') || (str_contains($apiMessage, 'sku') && (str_contains($apiMessage, 'exist') || str_contains($apiMessage, 'duplicate'))) => 'Le SKU existe déjà dans WooCommerce.',
            $response->serverError() => 'WooCommerce est momentanément indisponible.',
            default => 'La requête WooCommerce a échoué.',
        };

        Log::error('WooCommerce HTTP error', [
            'endpoint' => $path,
            'status' => $response->status(),
        ]);

        return new WooCommerceException($message, $response->status(), null, $imageError);
    }

    private function logProductStart(Produit $produit): void
    {
        Log::info('WooCommerce product sync started', ['product_id' => $produit->id, 'sku' => $produit->ref, 'woocommerce_id' => $produit->woocommerce_id]);
    }

    private function logProductSuccess(Produit $produit): void
    {
        Log::info('WooCommerce product sync success', ['product_id' => $produit->id, 'sku' => $produit->ref, 'woocommerce_id' => $produit->woocommerce_id]);
    }

    private function logProductFailure(Produit $produit, WooCommerceException $exception): void
    {
        Log::error('WooCommerce product sync failed', ['product_id' => $produit->id, 'sku' => $produit->ref, 'woocommerce_id' => $produit->woocommerce_id, 'status' => $exception->statusCode()]);
    }

    private function logCategoryFailure(Categorie $categorie, WooCommerceException $exception): void
    {
        Log::error('WooCommerce category sync failed', ['category_id' => $categorie->id, 'woocommerce_id' => $categorie->woocommerce_id, 'status' => $exception->statusCode()]);
    }
}
