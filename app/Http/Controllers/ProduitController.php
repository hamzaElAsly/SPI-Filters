<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProduitRequest;
use App\Http\Requests\UpdateProduitRequest;
use App\Models\Produit;
use App\Models\Categorie;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

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
                    $q->where('ref', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($categorieId !== '', function ($query) use ($categorieId) {
                $query->where('categorie_id', $categorieId);
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();
        $categories = Categorie::query()->orderBy('nom')->get();
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
        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('produits', 'public');
        }
        Produit::create($data);

        return to_route('produits.index')->with('success', 'Produit ajouté avec succès.');
    }

    public function update(UpdateProduitRequest $request, Produit $produit): RedirectResponse
    {
        $data = $request->validated();
        if ($request->hasFile('image')) {
            if ($produit->image) Storage::disk('public')->delete($produit->image);
            $data['image'] = $request->file('image')->store('produits', 'public');
        }
        $produit->update($data);

        return to_route('produits.index')->with('success', 'Produit modifié avec succès.');
    }

    public function destroy(Produit $produit): RedirectResponse
    {
        if ($produit->image) Storage::disk('public')->delete($produit->image);
        $produit->delete();

        return to_route('produits.index')->with('success', 'Produit supprimé avec succès.');
    }
}
