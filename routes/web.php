<?php

use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\CategorieController;
use App\Http\Controllers\ProduitController;
use App\Http\Controllers\UtilisateurController;
use App\Http\Controllers\WooCommerceSyncController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? to_route('produits.index') : to_route('login');
});

Route::middleware('guest')->group(function () {
    Route::get('/connexion', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/connexion', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/deconnexion', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/produits', [ProduitController::class, 'index'])->name('produits.index');
    Route::middleware('admin')->group(function () {
        Route::post('/produits', [ProduitController::class, 'store'])->name('produits.store');
        Route::put('/produits/{produit}', [ProduitController::class, 'update'])->name('produits.update');
        Route::delete('/produits/{produit}', [ProduitController::class, 'destroy'])->name('produits.destroy');
        Route::post('/produits/{produit}/woocommerce/sync', [WooCommerceSyncController::class, 'syncProduct'])->name('produits.woocommerce.sync');
        Route::post('/woocommerce/sync-products', [WooCommerceSyncController::class, 'syncProducts'])->name('woocommerce.sync-products');
        Route::post('/woocommerce/test', [WooCommerceSyncController::class, 'testConnection'])->name('woocommerce.test');
        Route::get('/categories', [CategorieController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategorieController::class, 'store'])->name('categories.store');
        Route::put('/categories/{categorie}', [CategorieController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{categorie}', [CategorieController::class, 'destroy'])->name('categories.destroy');
        Route::get('/utilisateurs', [UtilisateurController::class, 'index'])->name('utilisateurs.index');
        Route::post('/utilisateurs', [UtilisateurController::class, 'store'])->name('utilisateurs.store');
        Route::delete('/utilisateurs/{utilisateur}', [UtilisateurController::class, 'destroy'])->name('utilisateurs.destroy');
    });
});
