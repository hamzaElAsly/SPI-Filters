<?php

namespace App\Http\Controllers;

use App\Models\Categorie;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategorieController extends Controller
{
    public function index(): View
    {
        $categories = Categorie::query()->withCount('produits')->orderBy('nom')->get();

        return view('categories.index', compact('categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        Categorie::create($this->validated($request));

        return to_route('categories.index')->with('success', 'Catégorie ajoutée avec succès.');
    }

    public function update(Request $request, Categorie $categorie): RedirectResponse
    {
        $categorie->update($this->validated($request, $categorie));

        return to_route('categories.index')->with('success', 'Catégorie modifiée avec succès.');
    }

    public function destroy(Categorie $categorie): RedirectResponse
    {
        if ($categorie->produits()->exists()) {
            return to_route('categories.index')->with('error', 'Impossible de supprimer cette catégorie car elle est utilisée par un ou plusieurs produits.');
        }

        $categorie->delete();

        return to_route('categories.index')->with('success', 'Catégorie supprimée avec succès.');
    }

    private function validated(Request $request, ?Categorie $categorie = null): array
    {
        return $request->validate([
            'nom' => [
                'required', 'string', 'max:255',
                Rule::unique('categories', 'nom')->ignore($categorie?->id),
            ],
        ], [
            'nom.required' => 'Le nom de la catégorie est obligatoire.',
            'nom.unique' => 'Cette catégorie existe déjà.',
        ]);
    }
}
