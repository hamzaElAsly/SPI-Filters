<?php

namespace Tests\Feature;

use App\Models\Produit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ProductImageUploadTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'ADMIN']);
    }

    private function product(array $attributes = []): Produit
    {
        return Produit::create(array_merge([
            'ref' => 'IMG-'.uniqid(),
            'description' => 'Filtre de test',
            'prix_achat' => 10,
            'prix_vente' => 20,
        ], $attributes));
    }

    public function test_admin_can_create_a_product_with_a_relative_image_path(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->admin())->post('/produits', [
            'ref' => 'IMG-CREATE',
            'description' => 'Produit avec image',
            'prix_achat' => 10,
            'prix_vente' => 20,
            'image' => UploadedFile::fake()->image('produit.png'),
        ]);

        $response->assertRedirect('/produits');
        $product = Produit::where('ref', 'IMG-CREATE')->firstOrFail();

        $this->assertMatchesRegularExpression('#^produits/[^/]+\.(png|jpg|jpeg|webp)$#i', $product->image);
        $this->assertStringNotContainsString('storage/app/public', $product->image);
        $this->assertStringNotContainsString('://', $product->image);
        Storage::disk('public')->assertExists($product->image);
    }

    public function test_replacing_an_image_stores_the_new_file_before_deleting_the_old_one(): void
    {
        Storage::fake('public');
        $oldPath = 'produits/ancienne.png';
        Storage::disk('public')->put($oldPath, 'ancienne image');
        $product = $this->product(['image' => $oldPath]);

        $this->actingAs($this->admin())->put('/produits/'.$product->id, [
            'ref' => $product->ref,
            'description' => $product->description,
            'prix_achat' => 10,
            'prix_vente' => 20,
            'image' => UploadedFile::fake()->image('nouvelle.webp'),
        ])->assertRedirect('/produits');

        $newPath = $product->fresh()->image;
        $this->assertNotSame($oldPath, $newPath);
        $this->assertStringStartsWith('produits/', $newPath);
        Storage::disk('public')->assertExists($newPath);
        Storage::disk('public')->assertMissing($oldPath);
    }

    public function test_updating_without_a_new_image_keeps_the_existing_image(): void
    {
        Storage::fake('public');
        $oldPath = 'produits/conservee.png';
        Storage::disk('public')->put($oldPath, 'ancienne image');
        $product = $this->product(['image' => $oldPath]);

        $this->actingAs($this->admin())->put('/produits/'.$product->id, [
            'ref' => $product->ref,
            'description' => 'Description modifiée',
            'prix_achat' => 11,
            'prix_vente' => 21,
        ])->assertRedirect('/produits');

        $this->assertSame($oldPath, $product->fresh()->image);
        Storage::disk('public')->assertExists($oldPath);
    }

    public function test_product_view_uses_the_public_disk_url_for_images(): void
    {
        Storage::fake('public');
        $imagePath = 'produits/vue.png';
        Storage::disk('public')->put($imagePath, 'image');
        $this->product(['image' => $imagePath]);

        $this->actingAs($this->admin())
            ->get('/produits')
            ->assertOk()
            ->assertSee(Storage::disk('public')->url($imagePath), false);
    }

    public function test_rejects_an_unsupported_image_format(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/produits', [
            'ref' => 'IMG-PDF',
            'description' => 'Format invalide',
            'prix_achat' => 10,
            'prix_vente' => 20,
            'image' => UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrorsIn('storeProduit', 'image');

        $this->get('/produits?openModal=add')->assertOk()->assertSee('image valide');
        $this->assertDatabaseMissing('produits', ['ref' => 'IMG-PDF']);
    }

    public function test_rejects_an_image_larger_than_five_megabytes(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/produits', [
            'ref' => 'IMG-LARGE',
            'description' => 'Image trop grande',
            'prix_achat' => 10,
            'prix_vente' => 20,
            'image' => UploadedFile::fake()->image('large.jpg')->size(5121),
        ])->assertSessionHasErrorsIn('storeProduit', 'image');

        $this->assertDatabaseMissing('produits', ['ref' => 'IMG-LARGE']);
    }

    public function test_reports_a_file_write_failure_without_creating_a_product(): void
    {
        Storage::fake('public');
        $this->fakePublicDiskWriteFailure();

        $this->actingAs($this->admin())->post('/produits', [
            'ref' => 'IMG-WRITE-FAIL',
            'description' => 'Écriture impossible',
            'prix_achat' => 10,
            'prix_vente' => 20,
            'image' => UploadedFile::fake()->image('echec.jpg'),
        ])->assertRedirect('/produits?openModal=add')
            ->assertSessionHasErrorsIn('storeProduit', 'image');

        $this->assertDatabaseMissing('produits', ['ref' => 'IMG-WRITE-FAIL']);
    }

    public function test_file_write_failure_preserves_the_existing_image_and_database_path(): void
    {
        Storage::fake('public');
        $oldPath = 'produits/ancienne-conservee.png';
        Storage::disk('public')->put($oldPath, 'ancienne image');
        $product = $this->product(['image' => $oldPath]);
        $this->fakePublicDiskWriteFailure();

        $this->actingAs($this->admin())->put('/produits/'.$product->id, [
            'ref' => $product->ref,
            'description' => $product->description,
            'prix_achat' => 10,
            'prix_vente' => 20,
            'image' => UploadedFile::fake()->image('echec.jpg'),
        ])->assertRedirect('/produits?openModal=edit&edit='.$product->id)
            ->assertSessionHasErrorsIn('updateProduit', 'image');

        $this->assertSame($oldPath, $product->fresh()->image);
        Storage::disk('public')->assertExists($oldPath);
    }

    public function test_database_failure_after_upload_cleans_the_new_file_and_keeps_the_old_image(): void
    {
        Storage::fake('public');
        $oldPath = 'produits/ancienne-db.png';
        Storage::disk('public')->put($oldPath, 'ancienne image');
        $product = $this->product(['image' => $oldPath]);

        Produit::saving(function (Produit $saving) use ($product): bool {
            return ! $saving->is($product) || ! $saving->isDirty('image');
        });

        try {
            $this->actingAs($this->admin())->put('/produits/'.$product->id, [
                'ref' => $product->ref,
                'description' => $product->description,
                'prix_achat' => 10,
                'prix_vente' => 20,
                'image' => UploadedFile::fake()->image('nouvelle-db.png'),
            ])->assertRedirect('/produits?openModal=edit&edit='.$product->id)
                ->assertSessionHasErrorsIn('updateProduit', 'produit');
        } finally {
            Produit::flushEventListeners();
        }

        $this->assertSame($oldPath, $product->fresh()->image);
        $this->assertSame([$oldPath], Storage::disk('public')->allFiles('produits'));
    }

    private function fakePublicDiskWriteFailure(): void
    {
        $disk = Mockery::mock(Storage::disk('public'))->makePartial();
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);

        Storage::shouldReceive('disk')
            ->with('public')
            ->andReturn($disk);
    }
}
