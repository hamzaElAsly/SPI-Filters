<?php

namespace Tests\Feature;

use App\Models\Produit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductPriceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'ADMIN']);
    }

    public function test_old_price_column_exists_and_is_calculated_on_creation(): void
    {
        $this->assertTrue(Schema::hasColumn('produits', 'old_prix'));

        $response = $this->actingAs($this->admin())->post('/produits', [
            'ref' => 'PRICE-CREATE',
            'description' => 'Produit prix',
            'prix_achat' => 120,
            'prix_vente' => 200,
            'old_prix' => 999,
        ]);

        $response->assertRedirect('/produits');
        $product = Produit::where('ref', 'PRICE-CREATE')->firstOrFail();

        $this->assertSame('120.00', (string) $product->prix_achat);
        $this->assertSame('200.00', (string) $product->prix_vente);
        $this->assertSame('270.00', (string) $product->old_prix);
    }

    public function test_changing_sale_price_recalculates_old_price(): void
    {
        $product = Produit::create([
            'ref' => 'PRICE-UPDATE',
            'description' => 'Produit prix',
            'prix_achat' => 120,
            'prix_vente' => 200,
        ]);

        $this->actingAs($this->admin())->put('/produits/'.$product->id, [
            'ref' => $product->ref,
            'description' => $product->description,
            'prix_achat' => 120,
            'prix_vente' => 300,
        ])->assertRedirect('/produits');

        $this->assertSame('300.00', (string) $product->fresh()->prix_vente);
        $this->assertSame('405.00', (string) $product->fresh()->old_prix);
    }

    public function test_changing_purchase_price_only_does_not_change_sale_price_or_derived_price(): void
    {
        $product = Produit::create([
            'ref' => 'PRICE-BUY',
            'description' => 'Produit prix',
            'prix_achat' => 120,
            'prix_vente' => 200,
        ]);

        $this->actingAs($this->admin())->put('/produits/'.$product->id, [
            'ref' => $product->ref,
            'description' => $product->description,
            'prix_achat' => 150,
            'prix_vente' => 200,
            'old_prix' => 1,
        ])->assertRedirect('/produits');

        $product = $product->fresh();
        $this->assertSame('150.00', (string) $product->prix_achat);
        $this->assertSame('200.00', (string) $product->prix_vente);
        $this->assertSame('270.00', (string) $product->old_prix);
    }

    public function test_backfill_updates_only_old_price_and_verifies_all_products(): void
    {
        $product = Produit::create([
            'ref' => 'PRICE-BACKFILL',
            'description' => 'Produit à recalculer',
            'prix_achat' => 120,
            'prix_vente' => 200,
            'image' => 'produits/existante.png',
        ]);

        DB::table('produits')->where('id', $product->id)->update([
            'prix_vente' => '300.00',
            'old_prix' => null,
        ]);

        $before = DB::table('produits')->where('id', $product->id)->first();

        $this->artisan('products:backfill-old-prices')
            ->expectsOutput('1 produit(s) mis à jour. Vérification old_prix : OK.')
            ->assertExitCode(0);

        $after = DB::table('produits')->where('id', $product->id)->first();
        $this->assertEquals(120.0, (float) $after->prix_achat);
        $this->assertEquals(300.0, (float) $after->prix_vente);
        $this->assertEquals(405.0, (float) $after->old_prix);
        $this->assertSame($before->ref, $after->ref);
        $this->assertSame($before->description, $after->description);
        $this->assertSame($before->image, $after->image);
    }

    public function test_invalid_values_are_not_converted_to_an_old_price(): void
    {
        $this->assertNull(Produit::calculateOldPrice(null));
        $this->assertNull(Produit::calculateOldPrice('invalid'));
        $this->assertNull(Produit::calculateOldPrice(-1));
    }

    public function test_backfill_rejects_invalid_prices_without_partial_updates(): void
    {
        $valid = Produit::create([
            'ref' => 'PRICE-VALID-BACKFILL',
            'description' => 'Produit valide',
            'prix_achat' => 10,
            'prix_vente' => 100,
        ]);
        $invalid = Produit::create([
            'ref' => 'PRICE-INVALID-BACKFILL',
            'description' => 'Produit invalide',
            'prix_achat' => 10,
            'prix_vente' => 100,
        ]);

        DB::table('produits')->whereIn('id', [$valid->id, $invalid->id])->update([
            'old_prix' => null,
        ]);
        DB::table('produits')->where('id', $invalid->id)->update([
            'prix_vente' => -1,
        ]);

        $this->artisan('products:backfill-old-prices')
            ->expectsOutputToContain('prix de vente invalide')
            ->assertExitCode(1);

        $this->assertNull(DB::table('produits')->where('id', $valid->id)->value('old_prix'));
        $this->assertNull(DB::table('produits')->where('id', $invalid->id)->value('old_prix'));
    }
}
