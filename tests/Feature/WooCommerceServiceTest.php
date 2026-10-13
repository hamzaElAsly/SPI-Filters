<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\Produit;
use App\Models\User;
use App\Services\WooCommerceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WooCommerceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.woocommerce.url', 'https://shop.example.test/');
        config()->set('services.woocommerce.consumer_key', 'ck_test');
        config()->set('services.woocommerce.consumer_secret', 'cs_test');
        config()->set('services.woocommerce.retry_times', 1);
        config()->set('app.url', 'https://platform.example.test');
    }

    public function test_create_product_maps_fields_and_persists_woocommerce_state(): void
    {
        Http::fake(fn ($request) => Http::response(['id' => 41, 'sku' => 'SPI-1'], 201));
        Storage::fake('public');
        Storage::disk('public')->put('produits/filtre.jpg', 'image');
        Storage::disk('public')->assertExists('produits/filtre.jpg');
        $product = Produit::create(['ref' => 'SPI-1', 'description' => 'Filtre hydraulique', 'prix_achat' => 10, 'prix_vente' => 120, 'image' => 'produits/filtre.jpg']);
        $this->assertSame('produits/filtre.jpg', $product->image);

        $service = app(WooCommerceService::class);
        $this->assertSame('https://platform.example.test/storage/produits/filtre.jpg', $service->getProductImageUrl($product));
        $result = $service->createProduct($product);

        $this->assertSame(41, $result['id']);
        $this->assertDatabaseHas('produits', ['id' => $product->id, 'woocommerce_id' => 41]);
        $this->assertNotNull($product->fresh()->woocommerce_synced_at);
        Http::assertSent(function ($request) {
            $body = $request->data();
            return $request->method() === 'POST'
                && $request->url() === 'https://shop.example.test/wp-json/wc/v3/products'
                && $body['sku'] === 'SPI-1'
                && $body['name'] === 'Filtre hydraulique'
                && $body['regular_price'] === '120.00'
                && $body['images'] === [['src' => 'https://platform.example.test/storage/produits/filtre.jpg']]
                && $body['type'] === 'simple'
                && $body['status'] === 'publish'
                && !array_key_exists('prix_achat', $body);
        });
    }

    public function test_update_product_uses_woocommerce_id_and_refreshes_sync_timestamp(): void
    {
        Http::fake(fn ($request) => Http::response(['id' => 42], 200));
        $product = Produit::create(['ref' => 'SPI-2', 'description' => 'Filtre à air', 'prix_achat' => 10, 'prix_vente' => 80, 'woocommerce_id' => 42]);

        app(WooCommerceService::class)->updateProduct($product);

        $this->assertDatabaseHas('produits', ['id' => $product->id, 'woocommerce_id' => 42]);
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && str_ends_with($request->url(), '/products/42'));
    }

    public function test_sync_product_reuses_existing_sku_instead_of_creating_duplicate(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response([['id' => 77, 'sku' => 'SPI-3']], 200);
            }

            return Http::response(['id' => 77], 200);
        });
        $product = Produit::create(['ref' => 'SPI-3', 'description' => 'Filtre', 'prix_achat' => 10, 'prix_vente' => 20]);

        app(WooCommerceService::class)->syncProduct($product);

        $this->assertSame(77, $product->fresh()->woocommerce_id);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/products'));
    }

    public function test_category_is_created_and_attached_to_product_payload(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/products/categories')) {
                return Http::response([], 200);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/products/categories')) {
                return Http::response(['id' => 9, 'name' => 'Hydraulique'], 201);
            }

            return Http::response(['id' => 91], 201);
        });
        $category = Categorie::create(['nom' => 'Hydraulique']);
        $product = Produit::create(['ref' => 'SPI-4', 'description' => 'Filtre', 'prix_achat' => 10, 'prix_vente' => 20, 'categorie_id' => $category->id]);

        app(WooCommerceService::class)->createProduct($product);

        $this->assertSame(9, $category->fresh()->woocommerce_id);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/products')
                && $request->data()['categories'] === [['id' => 9]];
        });
    }

    public function test_find_product_by_sku_uses_api_query(): void
    {
        Http::fake(fn ($request) => Http::response([['id' => 12, 'sku' => 'SPI-5']], 200));

        $product = app(WooCommerceService::class)->findProductBySku('SPI-5');

        $this->assertSame(12, $product['id']);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://shop.example.test/wp-json/wc/v3/products?sku=SPI-5&per_page=10');
    }

    public function test_connection_returns_controlled_result_for_auth_error_and_server_error(): void
    {
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;

            return Http::response([], $calls === 1 ? 401 : 500);
        });
        $result = app(WooCommerceService::class)->testConnection();
        $this->assertFalse($result['success']);
        $this->assertSame('Authentification WooCommerce refusée.', $result['message']);

        $result = app(WooCommerceService::class)->testConnection();
        $this->assertFalse($result['success']);
        $this->assertSame('WooCommerce est momentanément indisponible.', $result['message']);
    }

    public function test_connection_returns_controlled_result_for_timeout_or_transport_failure(): void
    {
        Http::fake(fn ($request) => throw new ConnectionException('network failure'));

        $result = app(WooCommerceService::class)->testConnection();

        $this->assertFalse($result['success']);
        $this->assertSame('Impossible de se connecter à WooCommerce.', $result['message']);
    }

    public function test_image_is_omitted_with_warning_when_local_file_is_missing_or_url_is_local(): void
    {
        Http::fake(fn ($request) => Http::response(['id' => 51], 201));
        $product = Produit::create(['ref' => 'SPI-IMAGE', 'description' => 'Filtre', 'prix_achat' => 1, 'prix_vente' => 2, 'image' => 'produits/missing.jpg']);

        $service = app(WooCommerceService::class);
        $service->syncProduct($product);

        $this->assertNotNull($service->lastSyncWarning());
        Http::assertSent(fn ($request) => !array_key_exists('images', $request->data()));
    }

    public function test_image_download_error_retries_product_without_image(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('produits/remote.jpg', 'image');
        $posts = 0;
        Http::fake(function ($request) use (&$posts) {
            if ($request->method() === 'GET') {
                return Http::response([], 200);
            }

            $posts++;

            return $posts === 1
                ? Http::response(['code' => 'woocommerce_product_image_upload_error', 'message' => 'Could not download image'], 400)
                : Http::response(['id' => 52], 201);
        });
        $product = Produit::create(['ref' => 'SPI-REMOTE', 'description' => 'Filtre', 'prix_achat' => 1, 'prix_vente' => 2, 'image' => 'produits/remote.jpg']);

        $service = app(WooCommerceService::class);
        $service->syncProduct($product);

        $this->assertSame(52, $product->fresh()->woocommerce_id);
        $this->assertNotNull($service->lastSyncWarning());
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && !array_key_exists('images', $request->data()));
    }

    public function test_product_with_missing_remote_id_is_relinked_by_sku(): void
    {
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return Http::response([], 404);
            }
            if ($request->method() === 'GET') {
                return Http::response([['id' => 88, 'sku' => 'SPI-404']], 200);
            }

            return Http::response(['id' => 88], 200);
        });
        $product = Produit::create(['ref' => 'SPI-404', 'description' => 'Filtre', 'prix_achat' => 1, 'prix_vente' => 2, 'woocommerce_id' => 12]);

        app(WooCommerceService::class)->syncProduct($product);

        $this->assertSame(88, $product->fresh()->woocommerce_id);
        $this->assertNotNull($product->fresh()->woocommerce_synced_at);
    }

    public function test_admin_can_sync_product_and_viewer_cannot_sync_or_bulk_sync(): void
    {
        $product = Produit::create(['ref' => 'SPI-ADMIN', 'description' => 'Filtre', 'prix_achat' => 1, 'prix_vente' => 2]);
        $viewer = User::factory()->create(['role' => 'VIEWER']);
        $admin = User::factory()->create(['role' => 'ADMIN']);

        $this->actingAs($viewer)->get('/produits')->assertOk()->assertDontSee('Synchroniser WooCommerce')->assertDontSee('Tester connexion');
        $this->actingAs($admin)->get('/produits')->assertOk()->assertSee('Synchroniser WooCommerce')->assertSee('Tester connexion');
        $this->actingAs($viewer)->post('/produits/'.$product->id.'/woocommerce/sync')->assertForbidden();
        $this->actingAs($viewer)->post('/woocommerce/sync-products')->assertForbidden();

        Http::fake(fn ($request) => Http::response(['id' => 101], 201));
        $this->actingAs($admin)->post('/produits/'.$product->id.'/woocommerce/sync')
            ->assertRedirect('/produits')
            ->assertSessionHas('success', 'Produit synchronisé avec WooCommerce.');
        $this->assertDatabaseHas('produits', ['id' => $product->id, 'woocommerce_id' => 101]);
    }

    public function test_bulk_sync_continues_after_one_product_failure(): void
    {
        $first = Produit::create(['ref' => 'SPI-BULK-1', 'description' => 'Premier', 'prix_achat' => 1, 'prix_vente' => 2]);
        $second = Produit::create(['ref' => 'SPI-BULK-2', 'description' => 'Second', 'prix_achat' => 1, 'prix_vente' => 2]);
        $admin = User::factory()->create(['role' => 'ADMIN']);
        Http::fake(function ($request) {
            return str_contains($request->url(), 'SPI-BULK-1')
                ? Http::response(['message' => 'failed'], 500)
                : Http::response(['id' => 202], 201);
        });

        $this->actingAs($admin)->post('/woocommerce/sync-products')
            ->assertRedirect('/produits')
            ->assertSessionHas('error', '1 produits synchronisés. 1 produits en erreur.');
        $this->assertDatabaseHas('produits', ['id' => $second->id, 'woocommerce_id' => 202]);
        $this->assertDatabaseMissing('produits', ['id' => $first->id, 'woocommerce_id' => 202]);
    }

    public function test_viewer_cannot_test_woocommerce_connection_and_admin_gets_controlled_message(): void
    {
        $viewer = User::factory()->create(['role' => 'VIEWER']);
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $this->actingAs($viewer)->post('/woocommerce/test')->assertForbidden();

        Http::fake(fn ($request) => Http::response([], 401));
        $this->actingAs($admin)->post('/woocommerce/test')
            ->assertRedirect('/produits')
            ->assertSessionHas('error', 'Les identifiants WooCommerce sont invalides.')
            ->assertSessionMissing('WOOCOMMERCE_CONSUMER_SECRET');
    }

    public function test_category_crud_is_admin_only_and_used_category_cannot_be_deleted(): void
    {
        $viewer = User::factory()->create(['role' => 'VIEWER']);
        $this->actingAs($viewer)->get('/categories')->assertForbidden();
        $this->actingAs($viewer)->post('/categories', ['nom' => 'Interdit'])->assertForbidden();

        $admin = User::factory()->create(['role' => 'ADMIN']);
        $this->actingAs($admin)->post('/categories', ['nom' => 'Hydraulique'])->assertRedirect('/categories');
        $category = Categorie::firstOrFail();
        $product = Produit::create(['ref' => 'USED-1', 'description' => 'Filtre', 'prix_achat' => 1, 'prix_vente' => 2, 'categorie_id' => $category->id]);

        $this->actingAs($admin)->delete('/categories/'.$category->id)
            ->assertRedirect('/categories')
            ->assertSessionHas('error', 'Impossible de supprimer cette catégorie car elle est utilisée par un ou plusieurs produits.');
        $this->assertDatabaseHas('produits', ['id' => $product->id]);
    }
}
