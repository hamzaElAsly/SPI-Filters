@extends('layouts.app')

@php
  $isAdmin = auth()->user()->isAdmin();
  $productPayload = $produits
      ->getCollection()
      ->map(
          fn($produit) => [
              'id' => $produit->id,
              'ref' => $produit->ref,
              'description' => $produit->description,
              'categorie_id' => $produit->categorie_id,
              'categorie' => $produit->categorie?->nom,
              'prix_achat' => (string) $produit->prix_achat,
              'prix_vente' => (string) $produit->prix_vente,
              'old_prix' => (string) $produit->old_prix,
              'image' => $produit->image ? \Illuminate\Support\Facades\Storage::disk('public')->url($produit->image) : null,
              'woocommerce_id' => $produit->woocommerce_id,
              'woocommerce_synced_at' => $produit->woocommerce_synced_at?->format('d/m/Y H:i'),
          ],
      )
      ->values();
  $oldEdit = [
      'ref' => old('ref'),
      'description' => old('description'),
      'prix_achat' => old('prix_achat'),
      'prix_vente' => old('prix_vente'),
      'categorie_id' => old('categorie_id'),
  ];
  $oldAdd = [
      'ref' => old('ref'),
      'description' => old('description'),
      'prix_achat' => old('prix_achat'),
      'prix_vente' => old('prix_vente'),
      'categorie_id' => old('categorie_id'),
  ];
@endphp

@section('content')
  <div x-data="productsPage(@js($productPayload), @js(request('edit')), @js($oldEdit), @js($oldAdd))" x-init="init()" @keydown.escape.window="closeAll()">
    <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-start">
      <div>
        <p class="mb-2 text-xs font-bold uppercase tracking-[0.2em] text-[#A6192E]">Catalogue</p>
        <h1 class="text-3xl font-bold tracking-tight text-[#062B4A]">Produits</h1>
        <p class="mt-2 text-sm text-slate-500">Gérez et consultez les produits SPI FILTERS.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        @if ($isAdmin)
          <form method="POST" action="{{ route('woocommerce.test') }}" x-data="{ loading: false }" @submit="loading = true">
            @csrf<button class="btn-secondary" type="submit" :disabled="loading"><span
                x-text="loading ? 'Test...' : 'Tester connexion'"></span></button></form>
          <form method="POST" action="{{ route('woocommerce.sync-products') }}" x-data="{ loading: false }"
            @submit="loading = true">@csrf<button class="btn-secondary" type="submit" :disabled="loading"><span
                x-text="loading ? 'Synchronisation...' : 'Synchroniser WooCommerce'"></span></button></form>
          <button @click="openAdd()" class="btn-primary"><span class="text-lg leading-none">+</span> Ajouter un
            produit</button>
        @endif
      </div>
    </div>
{{-- ========================================================================================== --}}
@php($importSummary = session('import_summary'))
<div x-data="{ openImport: {{ $errors->has('fichier') || $importSummary ? 'true' : 'false' }}, loading: false }">
  <button type="button" @click="openImport = true" class="btn-secondary">Importer Excel</button>

  <div x-show="openImport" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
    @keydown.escape.window="openImport = false">
    <div @click.outside="openImport = false" class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
      <h2 class="text-xl font-bold text-[#062B4A]">Importer des produits</h2>
      <p class="mt-1 text-sm text-slate-500">
        Fichier .xlsx — ligne 2 : <strong>REF | PRIX | DESIGNATION</strong>, produits à partir de la ligne 3.
      </p>

      @if ($importSummary)
        <div class="mt-4 rounded-lg border p-3 text-sm {{ empty($importSummary['errors']) ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50' }}">
          <p class="font-bold text-[#062B4A]">
            {{ $importSummary['dry_run'] ? 'Simulation terminée : aucune donnée écrite.' : 'Import terminé.' }}
          </p>
          <ul class="mt-1 text-slate-700">
            <li>Lignes lues : {{ $importSummary['rows'] }}</li>
            <li>Créés : {{ $importSummary['imported'] }}</li>
            <li>Mis à jour : {{ $importSummary['updated'] }}</li>
            <li>Ignorés : {{ $importSummary['skipped'] }}</li>
            <li>Erreurs : {{ count($importSummary['errors']) }}</li>
          </ul>
          @if (! empty($importSummary['errors']))
            <ul class="mt-2 max-h-32 overflow-y-auto text-red-600">
              @foreach (array_slice($importSummary['errors'], 0, 20) as $err)
                <li>Ligne {{ $err['row'] }} : {{ $err['message'] }}</li>
              @endforeach
            </ul>
            @if (count($importSummary['errors']) > 20)
              <p class="mt-1 text-slate-500">+ {{ count($importSummary['errors']) - 20 }} autre(s) erreur(s).</p>
            @endif
          @endif
        </div>
      @endif

      <form method="POST" action="{{ route('produits.import-excel') }}" enctype="multipart/form-data"
        class="mt-5 space-y-4" @submit="loading = true">
        @csrf

        <div>
          <label class="mb-1 block text-sm font-semibold text-slate-700">Fichier Excel</label>
          <input type="file" name="fichier" accept=".xlsx" required
            class="block w-full rounded-lg border border-slate-300 p-2 text-sm">
          @error('fichier')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
          @enderror
        </div>

        <div>
          <label class="mb-1 block text-sm font-semibold text-slate-700">Si la référence existe déjà</label>
          <select name="duplicate" class="block w-full rounded-lg border border-slate-300 p-2 text-sm">
            <option value="update">Mettre à jour</option>
            <option value="skip">Ignorer</option>
            <option value="error">Signaler une erreur</option>
          </select>
        </div>

        <label class="flex items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" name="dry_run" value="1" checked class="rounded">
          Simulation (rien n’est enregistré)
        </label>

        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="openImport = false">Fermer</button>
          <button type="submit" class="btn-primary" :disabled="loading">
            <span x-text="loading ? 'Import en cours...' : 'Importer'"></span>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
{{-- ========================================================================================== --}}

    <div class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div class="border-b border-slate-100 p-4 sm:p-5">
        <form method="GET" action="{{ route('produits.index') }}"
          class="flex flex-col gap-3 lg:flex-row lg:items-center">
          <!-- Recherche -->
          <div class="relative w-full flex-1">
            <input type="text" name="search" value="{{ $search }}" class="field mt-0 w-full"
              placeholder="Rechercher par référence ou description..." aria-label="Rechercher un produit">
          </div>

          <!-- Filtre catégorie -->
          <div class="">
            <select name="categorie_id" class="field mt-0 w-full" aria-label="Filtrer par catégorie">
              <option value="">Toutes les catégories</option>

              @foreach ($categories as $categorie)
                <option value="{{ $categorie->id }}" @selected((string) $categorieId === (string) $categorie->id)>
                  {{ $categorie->nom }}
                </option>
              @endforeach
            </select>
          </div>
          <button type="submit" class="rounded-lg bg-[#062B4A] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[#0A406B]">
            Rechercher
          </button>

          <a href="{{ route('produits.index') }}" class="rounded-lg border border-slate-200 px-5 py-3 text-center text-sm font-semibold text-slate-600 transition hover:bg-slate-50">
            Réinitialiser
          </a>
        </form>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[980px] text-left">
          <thead class="bg-slate-50/70 text-xs uppercase tracking-wider text-slate-500">
            <tr>
              <th class="px-5 py-4 font-semibold">Image</th>
              <th class="px-5 py-4 font-semibold">Référence</th>
              <th class="px-5 py-4 font-semibold">Description</th>
              <th class="px-5 py-4 font-semibold">Catégorie</th>
              @if ($isAdmin)
                <th class="px-5 py-4 font-semibold">Prix d'achat</th>
              @endif
              <th class="px-5 py-4 font-semibold">Prix de vente</th>
              @if ($isAdmin)
                <th class="px-5 py-4 font-semibold">WooCommerce</th>
              @endif
              <th class="px-5 py-4 text-right font-semibold">
                Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse($produits as $produit)
              @php($productData = ['id' => $produit->id, 'ref' => $produit->ref, 'description' => $produit->description, 'categorie_id' => $produit->categorie_id, 'categorie' => $produit->categorie?->nom, 'prix_achat' => (string) $produit->prix_achat, 'prix_vente' => (string) $produit->prix_vente, 'old_prix' => (string) $produit->old_prix, 'image' => $produit->image ? \Illuminate\Support\Facades\Storage::disk('public')->url($produit->image) : null, 'woocommerce_id' => $produit->woocommerce_id, 'woocommerce_synced_at' => $produit->woocommerce_synced_at?->format('d/m/Y H:i')])
              <tr class="transition hover:bg-slate-50/60">
                <td class="px-5 py-4">
                  <div
                    class="flex h-12 w-12 items-center justify-center overflow-hidden rounded-lg border border-slate-100 bg-slate-50">
                    @if ($produit->image)
                      <img 
                      {{-- src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($produit->image) }}" --}}
                      src="{{ asset('storage/' . $produit->image) }}"
                      alt="{{ $produit->ref }}" class="h-full w-full object-cover">
                    @else<span
                        class="text-[10px] font-bold tracking-wider text-slate-300">SPI</span>
                    @endif
                  </div>
                </td>
                <td class="px-5 py-4"><span
                    class="rounded-md bg-[#062B4A]/5 px-2.5 py-1 text-sm font-bold text-[#062B4A]">{{ $produit->ref }}</span>
                </td>
                <td class="max-w-xs px-5 py-4 text-sm text-slate-600"><span
                    class="line-clamp-2">{{ $produit->description }}</span></td>
                <td class="px-5 py-4 text-sm text-slate-600">{{ $produit->categorie?->nom ?? 'Sans catégorie' }}</td>
                @if ($isAdmin)
                  <td class="whitespace-nowrap px-5 py-4 text-sm font-medium text-slate-700">
                    {{ number_format((float) $produit->prix_achat, 2, ',', ' ') }} DH</td>
                @endif
                <td class="whitespace-nowrap px-5 py-4 text-sm font-semibold text-[#062B4A]">
                  {{ number_format((float) $produit->prix_vente, 2, ',', ' ') }} DH</td>
                @if ($isAdmin)
                  <td class="px-5 py-4">
                    <div
                      class="text-xs font-semibold {{ $produit->woocommerce_id ? 'text-emerald-700' : 'text-slate-500' }}">
                      {{ $produit->woocommerce_id ? '● Synchronisé' : '○ Non synchronisé' }}</div>
                    @if ($produit->woocommerce_synced_at)
                      <div class="mt-1 text-[11px] text-slate-400">Sync :
                        {{ $produit->woocommerce_synced_at->format('d/m/Y H:i') }}</div>
                    @endif
                  </td>
                @endif
                <td class="px-5 py-4">
                  <div class="flex justify-end gap-1"><button class="icon-btn" title="Voir les détails"
                      @click="openDetails({{ Js::from($productData) }})"><i class="bi bi-eye"></i></button>
                    @if ($isAdmin)
                      {{-- Modifier --}}
                      <button class="icon-btn" title="Modifier" @click="openEdit({{ Js::from($productData) }})">
                        <i class="bi bi-pencil-square"></i>
                      </button>
                      {{-- Synchroniser WooCommerce --}}
                      <form method="POST" action="{{ route('produits.woocommerce.sync', $produit) }}"
                        x-data="{ loading: false }" @submit="loading = true">
                        @csrf
                        <button class="icon-btn text-[#062B4A]" title="Synchroniser avec WooCommerce"
                          type="submit":disabled="loading">
                          <i class="bi bi-arrow-repeat" :class="{ 'spi-spin': loading }" aria-hidden="true"></i>
                          <span class="sr-only">Synchroniser avec WooCommerce</span>
                        </button>
                      </form>
                      {{-- Supprimer --}}
                      <button class="icon-btn text-red-500" title="Supprimer"
                        @click="openDelete({{ Js::from(['id' => $produit->id, 'ref' => $produit->ref]) }})">
                        <i class="bi bi-trash3"></i>
                      </button>
                    @endif
                  </div>
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="{{ $isAdmin ? 8 : 7 }}" class="px-5 py-16 text-center">
                  <p class="text-sm font-semibold text-slate-700">
                    {{ $search ? 'Aucun produit ne correspond à votre recherche.' : 'Aucun produit disponible.' }}</p>
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
      @if ($produits->hasPages())
        <div class="border-t border-slate-100 px-5 py-4">{{ $produits->onEachSide(1)->links() }}</div>
      @endif
    </div>

    @if ($isAdmin)
      <div x-show="modal === 'add'" x-cloak x-transition.opacity
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-5">
        <div @click.outside="closeAll()"
          class="max-h-[95vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-xl sm:p-8">
          <div class="flex items-start justify-between">
            <h2 class="text-xl font-bold text-[#062B4A]">Ajouter un produit</h2><button @click="closeAll()"
              class="icon-btn" aria-label="Fermer">×</button>
          </div>
          <form method="POST" action="{{ route('produits.store') }}" enctype="multipart/form-data"
            class="mt-6 space-y-4">@csrf<div class="grid gap-4 sm:grid-cols-2">
              <div><label class="label" for="add_ref">Référence *</label><input id="add_ref" name="ref"
                  x-model="addForm.ref" class="field" required></div>
              <div><label class="label" for="add_image">Image</label><input id="add_image" name="image"
                  type="file" accept="image/jpeg,image/png,image/webp" class="field">
                @if ($errors->storeProduit->has('image'))
                  <p class="mt-1 text-xs text-red-600">{{ $errors->storeProduit->first('image') }}</p>
                @endif
              </div>
            </div>
            <div><label class="label" for="add_description">Description *</label>
              <textarea id="add_description" name="description" x-model="addForm.description" rows="3" class="field"
                required></textarea>
            </div>
            <div><label class="label" for="add_category">Catégorie</label><select id="add_category"
                name="categorie_id" x-model="addForm.categorie_id" class="field">
                <option value="">Sans catégorie</option>
                @foreach ($categories as $categorie)
                  <option value="{{ $categorie->id }}">{{ $categorie->nom }}</option>
                @endforeach
              </select>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <div><label class="label" for="add_buy">Prix d'achat *</label><input id="add_buy" name="prix_achat"
                  type="number" min="0" step="0.01" x-model="addForm.prix_achat" class="field" required>
              </div>
              <div><label class="label" for="add_sell">Prix de vente *</label><input id="add_sell" name="prix_vente"
                  type="number" min="0" step="0.01" x-model="addForm.prix_vente" class="field" required>
              </div>
              <div class="rounded-lg bg-slate-50 p-3 sm:col-span-2">
                <div class="flex items-center justify-between gap-4 text-sm">
                  <span class="font-semibold text-slate-600">Ancien prix calculé</span>
                  <span class="font-bold text-[#062B4A]"><span x-text="calculateOldPrice(addForm.prix_vente)"></span> DH</span>
                </div>
                <p class="mt-1 text-xs text-slate-400">Calculé automatiquement à partir du prix de vente.</p>
              </div>
            </div>
            <div class="flex justify-end gap-3"><button type="button" @click="closeAll()"
                class="btn-secondary">Annuler</button><button class="btn-primary">Ajouter le produit</button></div>
          </form>
        </div>
      </div>
      <div x-show="modal === 'edit'" x-cloak x-transition.opacity
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-5">
        <div @click.outside="closeAll()"
          class="max-h-[95vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-xl sm:p-8">
          <div class="flex items-start justify-between">
            <h2 class="text-xl font-bold text-[#062B4A]">Modifier le produit</h2><button @click="closeAll()"
              class="icon-btn" aria-label="Fermer">×</button>
          </div>
          <form method="POST" :action="editProduct ? '/produits/' + editProduct.id : '#'" enctype="multipart/form-data"
            class="mt-6 space-y-4">@csrf @method('PUT')<div class="grid gap-4 sm:grid-cols-2">
              <div><label class="label" for="edit_ref">Référence *</label><input id="edit_ref" name="ref"
                  x-model="editForm.ref" class="field" required></div>
              <div><label class="label" for="edit_image">Remplacer l'image</label><input id="edit_image"
                  name="image" type="file" accept="image/jpeg,image/png,image/webp" class="field">
                @if ($errors->updateProduit->has('image'))
                  <p class="mt-1 text-xs text-red-600">{{ $errors->updateProduit->first('image') }}</p>
                @endif
              </div>
            </div>
            <div><label class="label" for="edit_description">Description *</label>
              <textarea id="edit_description" name="description" x-model="editForm.description" rows="3" class="field"
                required></textarea>
            </div>
            <div><label class="label" for="edit_category">Catégorie</label><select id="edit_category"
                name="categorie_id" x-model="editForm.categorie_id" class="field">
                <option value="">Sans catégorie</option>
                @foreach ($categories as $categorie)
                  <option value="{{ $categorie->id }}">{{ $categorie->nom }}</option>
                @endforeach
              </select>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <div><label class="label" for="edit_buy">Prix d'achat *</label><input id="edit_buy" name="prix_achat"
                  type="number" min="0" step="0.01" x-model="editForm.prix_achat" class="field" required>
              </div>
              <div><label class="label" for="edit_sell">Prix de vente *</label><input id="edit_sell"
                  name="prix_vente" type="number" min="0" step="0.01" x-model="editForm.prix_vente"
                  class="field" required></div>
              <div class="rounded-lg bg-slate-50 p-3 sm:col-span-2">
                <div class="flex items-center justify-between gap-4 text-sm">
                  <span class="font-semibold text-slate-600">Ancien prix calculé</span>
                  <span class="font-bold text-[#062B4A]"><span x-text="calculateOldPrice(editForm.prix_vente)"></span> DH</span>
                </div>
                <p class="mt-1 text-xs text-slate-400">Calculé automatiquement à partir du prix de vente.</p>
              </div>
            </div>
            <div class="flex justify-end gap-3"><button type="button" @click="closeAll()"
                class="btn-secondary">Annuler</button><button class="btn-primary">Enregistrer</button></div>
          </form>
        </div>
      </div>
      <div x-show="modal === 'delete'" x-cloak x-transition.opacity
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-5">
        <div @click.outside="closeAll()" class="w-full max-w-md rounded-2xl bg-white p-7 shadow-xl">
          <h2 class="text-xl font-bold text-[#062B4A]">Supprimer ce produit ?</h2>
          <p class="mt-2 text-sm text-slate-500">Voulez-vous vraiment supprimer <strong
              x-text="deleteProduct?.ref"></strong> ?</p>
          <form method="POST" :action="deleteUrl" class="mt-7 flex justify-end gap-3">@csrf @method('DELETE')<button
              type="button" @click="closeAll()" class="btn-secondary">Annuler</button><button
              class="btn-primary bg-red-600 hover:bg-red-700">Supprimer</button></form>
        </div>
      </div>
    @endif

    <div x-show="modal === 'details'" x-cloak x-transition.opacity
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-5">
      <div @click.outside="closeAll()"
        class="max-h-[95vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-xl sm:p-8">
        <div class="flex items-start justify-between">
          <h2 class="text-xl font-bold text-[#062B4A]" x-text="selected?.ref"></h2><button @click="closeAll()"
            class="icon-btn" aria-label="Fermer">×</button>
        </div>
        <div
          class="mt-6 flex h-56 items-center justify-center overflow-hidden rounded-xl border border-slate-100 bg-slate-50">
          <template x-if="selected?.image"><img :src="selected?.image" :alt="selected?.ref"
              class="h-full w-full object-contain"></template><template x-if="!selected?.image"><span
              class="text-sm text-slate-300">Aucune image disponible</span></template>
        </div>
        <dl class="mt-6 divide-y divide-slate-100 text-sm">
          <div class="flex justify-between py-3">
            <dt class="text-slate-500">Référence</dt>
            <dd class="font-bold text-[#062B4A]" x-text="selected?.ref"></dd>
          </div>
          <div class="py-3">
            <dt class="text-slate-500">Description</dt>
            <dd class="mt-2 text-slate-700" x-text="selected?.description"></dd>
          </div>
          <div class="flex justify-between py-3">
            <dt class="text-slate-500">Catégorie</dt>
            <dd class="font-semibold text-slate-800" x-text="selected?.categorie || 'Sans catégorie'"></dd>
          </div>
          @if ($isAdmin)
            <div class="py-3">
              <dt class="text-slate-500">WooCommerce</dt>
              <dd class="mt-2"><span
                  x-text="selected?.woocommerce_id ? 'Synchronisé' : 'Non synchronisé'"></span><template
                  x-if="selected?.woocommerce_id"><span class="ml-2 text-slate-500">#<span
                      x-text="selected?.woocommerce_id"></span></span></template><template
                  x-if="selected?.woocommerce_synced_at">
                  <div class="mt-1 text-xs text-slate-400">Sync : <span x-text="selected?.woocommerce_synced_at"></span>
                  </div>
                </template></dd>
            </div>
          @endif
          <div class="flex justify-between py-3">
            <dt class="text-slate-500">Prix d'achat</dt>
            <dd><span x-text="formatPrice(selected?.prix_achat)"></span> DH</dd>
          </div>
          <div class="flex justify-between py-3">
            <dt class="text-slate-500">Prix de vente</dt>
            <dd class="font-bold text-[#062B4A]"><span x-text="formatPrice(selected?.prix_vente)"></span> DH</dd>
          </div>
          <div class="flex justify-between py-3">
            <dt class="text-slate-500">Ancien prix</dt>
            <dd class="font-bold text-slate-500"><span x-text="formatPrice(selected?.old_prix)"></span> DH</dd>
          </div>
        </dl>
      </div>
    </div>
  </div>
@endsection
