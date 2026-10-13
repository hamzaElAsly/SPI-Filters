@extends('layouts.app')

@section('content')
<div>
    <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-start"><div><p class="mb-2 text-xs font-bold uppercase tracking-[0.2em] text-[#A6192E]">Catalogue</p><h1 class="text-3xl font-bold tracking-tight text-[#062B4A]">Catégories</h1><p class="mt-2 text-sm text-slate-500">Organisez les produits du catalogue.</p></div></div>
    <div class="mt-8 grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm"><div class="border-b border-slate-100 p-5"><h2 class="font-bold text-[#062B4A]">Catégories existantes</h2></div><div class="divide-y divide-slate-100">
            @forelse($categories as $categorie)
                <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between"><div><p class="font-semibold text-slate-800">{{ $categorie->nom }}</p><p class="mt-1 text-xs text-slate-500">{{ $categorie->produits_count }} produit(s) associé(s) @if($categorie->woocommerce_id) · WooCommerce #{{ $categorie->woocommerce_id }}@endif</p></div><div class="flex items-center gap-2"><form method="POST" action="{{ route('categories.update', $categorie) }}" class="flex gap-2">@csrf @method('PUT')<label class="sr-only" for="category-{{ $categorie->id }}">Nom de la catégorie</label><input id="category-{{ $categorie->id }}" name="nom" value="{{ $categorie->nom }}" class="field mt-0 w-48"><button class="btn-secondary">Modifier</button></form>@if($categorie->produits_count === 0)<form method="POST" action="{{ route('categories.destroy', $categorie) }}" onsubmit="return confirm('Supprimer cette catégorie ?')">@csrf @method('DELETE')<button class="btn-secondary text-red-600 hover:bg-red-50">Supprimer</button></form>@endif</div></div>
            @empty
                <p class="p-8 text-center text-sm text-slate-500">Aucune catégorie pour le moment.</p>
            @endforelse
        </div></div>
        <div class="h-fit rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="font-bold text-[#062B4A]">Ajouter une catégorie</h2><form method="POST" action="{{ route('categories.store') }}" class="mt-5 space-y-4">@csrf<label class="label" for="nom">Nom</label><input id="nom" name="nom" value="{{ old('nom') }}" class="field" required>@error('nom')<p class="text-xs text-red-600">{{ $message }}</p>@enderror<button class="btn-primary w-full">Ajouter</button></form></div>
    </div>
</div>
@endsection
