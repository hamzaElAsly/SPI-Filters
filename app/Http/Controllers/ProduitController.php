<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProduitRequest;
use App\Http\Requests\UpdateProduitRequest;
use App\Models\Categorie;
use App\Models\Produit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class ProduitController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $categorieId = $request->query('categorie_id', '');

        $produits = Produit::query()
            ->with('categorie')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('ref', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($categorieId !== '', function ($query) use ($categorieId) {
                $query->where('categorie_id', $categorieId);
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $categories = Categorie::query()
            ->orderBy('nom')
            ->get();

        return view('produits.index', compact(
            'produits',
            'search',
            'categories',
            'categorieId'
        ));
    }

    public function store(StoreProduitRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // Le fichier sera remplacé par son chemin relatif.
        unset($data['image']);

        $imagePath = null;
        $step = 'storage';

        try {
            if ($request->hasFile('image')) {
                $imagePath = $this->storeProductImage(
                    $request->file('image')
                );

                $data['image'] = $imagePath;
            }

            $step = 'database';

            $produit = new Produit;
            $produit->fill($data);

            if (! $produit->save()) {
                throw new RuntimeException(
                    'La création du produit a échoué.'
                );
            }
        } catch (Throwable $exception) {
            if ($imagePath !== null) {
                $this->deleteStoredImage($imagePath, null);
            }

            $this->logFailure($step, null, $imagePath, $exception);

            return $this->failureResponse('add', null, $step);
        }

        return to_route('produits.index')
            ->with('success', 'Produit ajouté avec succès.');
    }

    public function update(
        UpdateProduitRequest $request,
        Produit $produit
    ): RedirectResponse {
        $data = $request->validated();

        // Conserver l’ancienne image si aucun nouveau fichier n’est envoyé.
        unset($data['image']);

        $oldImagePath = $produit->image;
        $newImagePath = null;
        $step = 'storage';

        try {
            if ($request->hasFile('image')) {
                $newImagePath = $this->storeProductImage(
                    $request->file('image')
                );

                $data['image'] = $newImagePath;
            }

            $step = 'database';

            if (! $produit->update($data)) {
                throw new RuntimeException(
                    'La mise à jour du produit a échoué.'
                );
            }
        } catch (Throwable $exception) {
            if ($newImagePath !== null) {
                $this->deleteStoredImage($newImagePath, $produit->id);
            }

            $this->logFailure(
                $step,
                $produit->id,
                $newImagePath,
                $exception
            );

            return $this->failureResponse('edit', $produit->id, $step);
        }

        $response = to_route('produits.index')
            ->with('success', 'Produit modifié avec succès.');

        // Supprimer l’ancienne image après la mise à jour réussie.
        if (
            $newImagePath !== null
            && ! empty($oldImagePath)
            && $oldImagePath !== $newImagePath
        ) {
            if (! $this->deleteStoredImage($oldImagePath, $produit->id)) {
                $response->with(
                    'warning',
                    'Produit modifié, mais l’ancienne image n’a pas pu être supprimée.'
                );
            }
        }

        return $response;
    }

    public function destroy(Produit $produit): RedirectResponse
    {
        $imagePath = $produit->image;
        $productId = $produit->id;

        try {
            if (! $produit->delete()) {
                throw new RuntimeException(
                    'La suppression du produit a échoué.'
                );
            }
        } catch (Throwable $exception) {
            $this->logFailure(
                'delete_product',
                $productId,
                $imagePath,
                $exception
            );

            return to_route('produits.index')
                ->with('error', 'Impossible de supprimer le produit.');
        }

        $response = to_route('produits.index')
            ->with('success', 'Produit supprimé avec succès.');

        if (! empty($imagePath)) {
            if (! $this->deleteStoredImage($imagePath, $productId)) {
                $response->with(
                    'warning',
                    'Produit supprimé, mais son image n’a pas pu être supprimée.'
                );
            }
        }

        return $response;
    }

    private function storeProductImage(UploadedFile $image): string
    {
        if (! $image->isValid()) {
            throw new RuntimeException('Le fichier image est invalide.');
        }

        $filename = $image->hashName();
        $disk = Storage::disk('public');
        $path = $disk->putFileAs(
            'produits',
            $image,
            $filename
        );

        if (
            $path === false
            || ! is_string($path)
            || ! str_starts_with($path, 'produits/')
            || str_contains($path, '..')
            || str_contains($path, '\\')
        ) {
            if (is_string($path)) {
                $disk->delete($path);
            }

            throw new RuntimeException(
                'Impossible d’enregistrer le fichier image.'
            );
        }

        if (! $disk->exists($path)) {
            $disk->delete($path);

            throw new RuntimeException(
                'Le fichier image n’est pas présent sur le stockage configuré.'
            );
        }

        return $path;
    }

    private function deleteStoredImage(string $path, ?int $productId): bool
    {
        if (
            ! str_starts_with($path, 'produits/')
            || str_contains($path, '..')
            || str_contains($path, '\\')
        ) {
            return false;
        }

        try {
            if (! Storage::disk('public')->exists($path)) {
                return true;
            }

            if (! Storage::disk('public')->delete($path)) {
                throw new RuntimeException(
                    'Impossible de supprimer l’image.'
                );
            }

            return true;
        } catch (Throwable $exception) {
            Log::error('Product image deletion failed', [
                'product_id' => $productId,
                'relative_path' => $path,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function logFailure(string $step, ?int $productId, ?string $relativePath, Throwable $exception): void
    {
        Log::error('Product operation failed', [
            'step' => $step,
            'product_id' => $productId,
            'disk' => 'public',
            'relative_path' => $relativePath,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    private function failureResponse(
        string $form,
        ?int $productId,
        string $step
    ): RedirectResponse {
        $query = [
            'openModal' => $form === 'add' ? 'add' : 'edit',
        ];

        if ($productId !== null) {
            $query['edit'] = $productId;
        }

        $field = $step === 'storage' ? 'image' : 'produit';

        $message = $step === 'storage'
            ? 'Impossible d’enregistrer l’image. Vérifiez les permissions du stockage.'
            : 'Impossible d’enregistrer le produit. Consultez les logs de l’application.';

        return to_route('produits.index', $query)
            ->withInput()
            ->withErrors(
                [$field => $message],
                $form === 'add' ? 'storeProduit' : 'updateProduit'
            );
    }
}
