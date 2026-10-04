<?php

namespace Tests\Feature;

use App\Models\Produit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccessAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'ADMIN']);
    }

    private function viewer(): User
    {
        return User::factory()->create(['role' => 'VIEWER']);
    }

    public function test_guest_cannot_access_products(): void
    {
        $this->get('/produits')->assertRedirect('/connexion');
    }

    public function test_user_can_login_and_logout(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN', 'email' => 'admin@example.com', 'password' => Hash::make('password123')]);

        $this->post('/connexion', ['email' => $admin->email, 'password' => 'password123'])->assertRedirect('/produits');
        $this->assertAuthenticatedAs($admin);
        $this->post('/deconnexion')->assertRedirect('/connexion');
        $this->assertGuest();
    }

    public function test_admin_and_viewer_can_access_products(): void
    {
        $this->actingAs($this->admin())->get('/produits')->assertOk();
        $this->actingAs($this->viewer())->get('/produits')->assertOk();
    }

    public function test_admin_can_create_update_and_delete_product(): void
    {
        $admin = $this->admin();
        $response = $this->actingAs($admin)->post('/produits', [
            'ref' => 'SPI-100', 'description' => 'Filtre hydraulique', 'prix_achat' => '10.50', 'prix_vente' => '15.00',
        ]);
        $response->assertRedirect('/produits');
        $product = Produit::firstOrFail();

        $this->actingAs($admin)->put('/produits/'.$product->id, [
            'ref' => 'SPI-101', 'description' => 'Filtre modifié', 'prix_achat' => '11', 'prix_vente' => '16',
        ])->assertRedirect('/produits');
        $this->assertDatabaseHas('produits', ['ref' => 'SPI-101']);

        $this->actingAs($admin)->delete('/produits/'.$product->id)->assertRedirect('/produits');
        $this->assertDatabaseCount('produits', 0);
    }

    public function test_viewer_cannot_write_products(): void
    {
        $viewer = $this->viewer();
        $this->actingAs($viewer)->post('/produits', [
            'ref' => 'SPI-100', 'description' => 'Interdit', 'prix_achat' => '1', 'prix_vente' => '2',
        ])->assertForbidden();

        $product = Produit::create(['ref' => 'SPI-200', 'description' => 'Filtre', 'prix_achat' => 1, 'prix_vente' => 2]);
        $this->actingAs($viewer)->put('/produits/'.$product->id, [
            'ref' => 'SPI-201', 'description' => 'Interdit', 'prix_achat' => '1', 'prix_vente' => '2',
        ])->assertForbidden();
        $this->actingAs($viewer)->delete('/produits/'.$product->id)->assertForbidden();
    }

    public function test_viewer_cannot_access_users_but_admin_can_manage_viewer(): void
    {
        $viewer = $this->viewer();
        $this->actingAs($viewer)->get('/utilisateurs')->assertForbidden();

        $admin = $this->admin();
        $this->actingAs($admin)->get('/utilisateurs')->assertOk();
        $this->actingAs($admin)->post('/utilisateurs', [
            'name' => 'Nouveau Viewer', 'email' => 'viewer2@example.com', 'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertRedirect('/utilisateurs');
        $created = User::where('email', 'viewer2@example.com')->firstOrFail();
        $this->assertSame('VIEWER', $created->role);
        $this->assertTrue(Hash::check('password123', $created->password));
        $this->actingAs($admin)->delete('/utilisateurs/'.$created->id)->assertRedirect('/utilisateurs');
        $this->assertDatabaseMissing('users', ['id' => $created->id]);
    }

    public function test_product_reference_and_viewer_email_are_unique(): void
    {
        $admin = $this->admin();
        Produit::create(['ref' => 'DUP-1', 'description' => 'Filtre', 'prix_achat' => 1, 'prix_vente' => 2]);
        $this->actingAs($admin)->post('/produits', ['ref' => 'DUP-1', 'description' => 'Autre', 'prix_achat' => 1, 'prix_vente' => 2])->assertSessionHasErrorsIn('storeProduit', 'ref');

        User::factory()->create(['email' => 'duplicate@example.com']);
        $this->actingAs($admin)->post('/utilisateurs', ['name' => 'Viewer', 'email' => 'duplicate@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertSessionHasErrorsIn('storeViewer', 'email');
    }

    public function test_product_search_is_available_to_authenticated_users(): void
    {
        $this->actingAs($this->viewer());
        Produit::create(['ref' => 'HYD-1', 'description' => 'Filtre hydraulique', 'prix_achat' => 1, 'prix_vente' => 2]);
        Produit::create(['ref' => 'AIR-1', 'description' => 'Filtre à air', 'prix_achat' => 1, 'prix_vente' => 2]);

        $this->get('/produits?search=hydraulique')->assertOk()->assertSee('HYD-1')->assertDontSee('AIR-1');
    }

    public function test_admin_can_upload_a_product_image(): void
    {
        Storage::fake('public');
        $admin = $this->admin();
        $this->actingAs($admin)->post('/produits', [
            'ref' => 'IMG-1', 'description' => 'Avec image', 'prix_achat' => 1, 'prix_vente' => 2,
            'image' => UploadedFile::fake()->image('filtre.jpg'),
        ])->assertRedirect('/produits');

        $product = Produit::where('ref', 'IMG-1')->firstOrFail();
        Storage::disk('public')->assertExists($product->image);
    }
}
