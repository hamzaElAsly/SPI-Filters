<?php

namespace App\Http\Controllers;

use App\Exceptions\WooCommerceException;
use App\Models\Produit;
use App\Services\WooCommerceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class WooCommerceSyncController extends Controller
{
    public function syncProduct(Produit $produit, WooCommerceService $woocommerce): RedirectResponse
    {
        try {
            $woocommerce->syncProduct($produit);

            $response = to_route('produits.index')->with('success', 'Produit synchronisé avec WooCommerce.');
            if ($woocommerce->lastSyncWarning()) {
                $response->with('warning', $woocommerce->lastSyncWarning());
            }

            return $response;
        } catch (WooCommerceException $exception) {
            return to_route('produits.index')->with('error', $this->syncErrorMessage($exception));
        } catch (Throwable $exception) {
            Log::error('WooCommerce product sync controller failure', ['product_id' => $produit->id, 'exception' => $exception::class]);

            return to_route('produits.index')->with('error', 'Impossible de synchroniser ce produit avec WooCommerce.');
        }
    }

    public function syncProducts(WooCommerceService $woocommerce): RedirectResponse
    {
        $successes = 0;
        $errors = 0;
        $imageWarnings = 0;

        Produit::query()
            ->with('categorie')
            ->chunkById(50, function (Collection $produits) use ($woocommerce, &$successes, &$errors, &$imageWarnings): void {
                foreach ($produits as $produit) {
                    try {
                        $woocommerce->syncProduct($produit);
                        $successes++;
                        $imageWarnings += $woocommerce->lastSyncWarning() ? 1 : 0;
                    } catch (WooCommerceException $exception) {
                        $errors++;
                        Log::error('WooCommerce bulk sync product failed', [
                            'product_id' => $produit->id,
                            'sku' => $produit->ref,
                            'status' => $exception->statusCode(),
                        ]);
                    } catch (Throwable $exception) {
                        $errors++;
                        Log::error('WooCommerce bulk sync product failed', [
                            'product_id' => $produit->id,
                            'sku' => $produit->ref,
                            'exception' => $exception::class,
                        ]);
                    }
                }
            });

        if ($errors === 0 && $imageWarnings === 0) {
            return to_route('produits.index')->with('success', 'Tous les produits ont été synchronisés avec succès.');
        }

        $message = sprintf('%d produits synchronisés. %d produits en erreur.', $successes, $errors);
        $response = to_route('produits.index')->with($errors > 0 ? 'error' : 'warning', $message);
        if ($imageWarnings > 0) {
            $response->with('warning', $message.' '.$imageWarnings.' image(s) n’ont pas pu être envoyée(s).');
        }

        return $response;
    }

    public function testConnection(WooCommerceService $woocommerce): RedirectResponse
    {
        $result = $woocommerce->testConnection();

        return to_route('produits.index')->with(
            $result['success'] ? 'success' : 'error',
            $result['success'] ? 'Connexion WooCommerce réussie.' : $this->connectionErrorMessage($result['status'] ?? null),
        );
    }

    private function syncErrorMessage(WooCommerceException $exception): string
    {
        return match ($exception->statusCode()) {
            401 => 'Les identifiants WooCommerce sont invalides.',
            403 => 'Accès WooCommerce refusé.',
            default => 'Impossible de synchroniser ce produit avec WooCommerce.',
        };
    }

    private function connectionErrorMessage(?int $status): string
    {
        return $status === 401
            ? 'Les identifiants WooCommerce sont invalides.'
            : 'Impossible de se connecter à WooCommerce.';
    }
}
