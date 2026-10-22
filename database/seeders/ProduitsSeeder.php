<?php

namespace Database\Seeders;

use App\Models\Produit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ProduitsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@filter.test'],
            [
                'name' => 'Administrateur SPI Filters',
                'password' => Hash::make('password'),
                'role' => 'ADMIN',
            ]
        );

        // $produits = [
        //     [
        //         'ref' => 'SPI-001',
        //         'description' => 'Filtre à huile moteur',
        //         'prix_achat' => 75.00,
        //         'prix_vente' => 110.00,
        //         'image' => null,
        //     ],
        //     [
        //         'ref' => 'SPI-002',
        //         'description' => 'Filtre à air moteur',
        //         'prix_achat' => 95.00,
        //         'prix_vente' => 140.00,
        //         'image' => null,
        //     ],
        //     [
        //         'ref' => 'SPI-003',
        //         'description' => 'Filtre à carburant diesel',
        //         'prix_achat' => 120.00,
        //         'prix_vente' => 175.00,
        //         'image' => null,
        //     ],
        //     [
        //         'ref' => 'SPI-004',
        //         'description' => 'Filtre hydraulique haute pression',
        //         'prix_achat' => 180.00,
        //         'prix_vente' => 260.00,
        //         'image' => null,
        //     ],
        //     [
        //         'ref' => 'SPI-005',
        //         'description' => 'Filtre séparateur eau carburant',
        //         'prix_achat' => 145.00,
        //         'prix_vente' => 210.00,
        //         'image' => null,
        //     ],
        //     [
        //         'ref' => 'SPI-006',
        //         'description' => 'Filtre à air primaire pour engins TP',
        //         'prix_achat' => 220.00,
        //         'prix_vente' => 320.00,
        //         'image' => null,
        //     ],
        //     [
        //         'ref' => 'SPI-007',
        //         'description' => 'Filtre à air secondaire',
        //         'prix_achat' => 160.00,
        //         'prix_vente' => 235.00,
        //         'image' => null,
        //     ],
        //     [
        //         'ref' => 'SPI-008',
        //         'description' => 'Filtre de transmission',
        //         'prix_achat' => 195.00,
        //         'prix_vente' => 285.00,
        //         'image' => null,
        //     ],
        //     [
        //         'ref' => 'SPI-009',
        //         'description' => 'Filtre hydraulique retour',
        //         'prix_achat' => 135.00,
        //         'prix_vente' => 200.00,
        //         'image' => null,
        //     ],
        //     [
        //         'ref' => 'SPI-010',
        //         'description' => 'Filtre à huile industriel',
        //         'prix_achat' => 105.00,
        //         'prix_vente' => 155.00,
        //         'image' => null,
        //     ],
        // ];

        // foreach ($produits as $produit) {
        //     Produit::updateOrCreate(
        //         ['ref' => $produit['ref']],
        //         $produit
        //     );
        // }
    }
}
