<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Produit extends Model
{
    use HasFactory;

    protected $fillable = [
        'ref',
        'description',
        'prix_achat',
        'prix_vente',
        'old_prix',
        'image',
        'categorie_id',
        'woocommerce_id',
        'woocommerce_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'prix_achat' => 'decimal:2',
            'prix_vente' => 'decimal:2',
            'old_prix' => 'decimal:2',
            'woocommerce_id' => 'integer',
            'woocommerce_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $produit): void {
            $produit->old_prix = self::calculateOldPrice($produit->prix_vente);
        });
    }

    public static function calculateOldPrice(mixed $prixVente): ?string
    {
        if ($prixVente === null || $prixVente === '' || ! is_numeric($prixVente)) {
            return null;
        }

        $prixVente = (float) $prixVente;
        if ($prixVente < 0 || ! is_finite($prixVente)) {
            return null;
        }

        return number_format(round($prixVente * 1.35, 2), 2, '.', '');
    }

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(Categorie::class);
    }
}
