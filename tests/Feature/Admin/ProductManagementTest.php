<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Products\RelationManagers\ImagesRelationManager;
use App\Filament\Resources\Products\RelationManagers\SpecificationsRelationManager;
use App\Filament\Support\Uploads;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

class ProductManagementTest extends AdminTestCase
{
    private function validData(Category $category, array $overrides = []): array
    {
        return [
            'name' => 'Combi Oven 10 Tray',
            'slug' => 'combi-oven-10-tray',
            'sku' => 'CO-10T',
            'category_id' => $category->id,
            'status' => 'draft',
            'sort_order' => 0,
            ...$overrides,
        ];
    }

    /** A fresh component per attempt: a failed validation leaves the modal open on the old one. */
    private function manager(string $relationManager, Product $product): Testable
    {
        return Livewire::actingAs($this->catalogueManager())->test($relationManager, [
            'ownerRecord' => $product, 'pageClass' => EditProduct::class,
        ]);
    }

    public function test_catalogue_manager_creates_a_draft_product_without_a_price(): void
    {
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();

        Livewire::actingAs($this->catalogueManager())
            ->test(CreateProduct::class)
            ->fillForm($this->validData($category, ['brand_id' => $brand->id, 'short_description' => 'Steam and convection.']))
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::where('sku', 'CO-10T')->firstOrFail();
        $this->assertSame('draft', $product->status);
        $this->assertNull($product->published_at);
        $this->assertNull($product->price);
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame($brand->id, $product->brand_id);
        $this->assertNotNull($product->created_by);
    }

    public function test_duplicate_sku_and_slug_are_rejected_on_create_and_edit(): void
    {
        $category = Category::factory()->create();
        Product::factory()->create(['sku' => 'TAKEN', 'slug' => 'taken-slug', 'category_id' => $category->id]);
        $other = Product::factory()->create(['category_id' => $category->id]);
        $manager = $this->catalogueManager();

        Livewire::actingAs($manager)->test(CreateProduct::class)
            ->fillForm($this->validData($category, ['sku' => 'TAKEN', 'slug' => 'taken-slug']))
            ->call('create')
            ->assertHasFormErrors(['sku' => 'unique', 'slug' => 'unique']);

        Livewire::actingAs($manager)->test(EditProduct::class, ['record' => $other->getKey()])
            ->fillForm(['sku' => 'TAKEN', 'slug' => 'taken-slug'])
            ->call('save')
            ->assertHasFormErrors(['sku' => 'unique', 'slug' => 'unique']);

        $this->assertNotSame('TAKEN', $other->fresh()->sku);
    }

    public function test_an_archived_products_slug_still_blocks_reuse(): void
    {
        $category = Category::factory()->create();
        $archived = Product::factory()->create(['slug' => 'old-slug', 'sku' => 'OLD-1', 'category_id' => $category->id]);
        $archived->delete();

        Livewire::actingAs($this->superAdmin())->test(CreateProduct::class)
            ->fillForm($this->validData($category, ['slug' => 'old-slug', 'sku' => 'NEW-1']))
            ->call('create')
            ->assertHasFormErrors(['slug' => 'unique']);
    }

    public function test_editing_a_product_keeps_its_slug_unless_changed_deliberately(): void
    {
        $product = Product::factory()->create(['name' => 'Original', 'slug' => 'original']);

        Livewire::actingAs($this->catalogueManager())->test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['name' => 'Renamed Product'])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame('Renamed Product', $product->name);
        $this->assertSame('original', $product->slug);
    }

    public function test_price_requires_a_currency(): void
    {
        Livewire::actingAs($this->catalogueManager())->test(CreateProduct::class)
            ->fillForm($this->validData(Category::factory()->create(), ['price' => '1500.00', 'currency_code' => null]))
            ->call('create')
            ->assertHasFormErrors(['currency_code']);
    }

    public function test_unpublished_products_stay_out_of_the_public_api_until_published(): void
    {
        $category = Category::factory()->create();
        $manager = $this->catalogueManager();

        Livewire::actingAs($manager)->test(CreateProduct::class)
            ->fillForm($this->validData($category))
            ->call('create')->assertHasNoFormErrors();

        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/products/combi-oven-10-tray')->assertNotFound();

        Livewire::actingAs($manager)->test(ListProducts::class)
            ->callAction(TestAction::make('publish')->table(Product::where('sku', 'CO-10T')->first()));

        $this->getJson('/api/v1/products')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'combi-oven-10-tray');
        $this->getJson('/api/v1/products/combi-oven-10-tray')->assertOk()
            ->assertJsonPath('data.name', 'Combi Oven 10 Tray');
    }

    public function test_edits_unpublish_and_archive_are_reflected_in_the_public_api(): void
    {
        $product = Product::factory()->published()->create(['slug' => 'live-item', 'name' => 'Live Item']);
        $manager = $this->catalogueManager();

        Livewire::actingAs($manager)->test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['name' => 'Live Item v2'])
            ->call('save');
        $this->getJson('/api/v1/products/live-item')->assertOk()->assertJsonPath('data.name', 'Live Item v2');

        Livewire::actingAs($manager)->test(ListProducts::class)
            ->callAction(TestAction::make('unpublish')->table($product));
        $this->assertSame('draft', $product->fresh()->status);
        $this->getJson('/api/v1/products/live-item')->assertNotFound();

        Livewire::actingAs($manager)->test(ListProducts::class)
            ->callAction(TestAction::make('publish')->table($product));
        $this->getJson('/api/v1/products/live-item')->assertOk();

        Livewire::actingAs($manager)->test(ListProducts::class)
            ->callAction(TestAction::make('archive')->table($product));
        $this->assertSame('archived', $product->fresh()->status);
        $this->getJson('/api/v1/products')->assertJsonCount(0, 'data');
    }

    public function test_sales_roles_cannot_open_or_change_products(): void
    {
        $product = Product::factory()->create();

        foreach ([User::ROLE_SALES_EXECUTIVE, User::ROLE_SALES_MANAGER] as $role) {
            Livewire::actingAs($this->staff($role))->test(ListProducts::class)->assertForbidden();
            Livewire::actingAs($this->staff($role))->test(EditProduct::class, ['record' => $product->getKey()])->assertForbidden();
        }
    }

    public function test_catalogue_manager_cannot_delete_but_super_admin_can_soft_delete(): void
    {
        $product = Product::factory()->create();

        Livewire::actingAs($this->catalogueManager())->test(EditProduct::class, ['record' => $product->getKey()])
            ->assertActionHidden('delete');

        Livewire::actingAs($this->superAdmin())->test(EditProduct::class, ['record' => $product->getKey()])
            ->callAction('delete');

        $this->assertSoftDeleted($product);
    }

    public function test_list_search_and_filters(): void
    {
        $mixer = Product::factory()->create(['name' => 'Spiral Mixer', 'sku' => 'MIX-1']);
        $cooler = Product::factory()->published()->create(['name' => 'Walk-in Cooler', 'sku' => 'COOL-1']);

        Livewire::actingAs($this->catalogueManager())->test(ListProducts::class)
            ->assertCanSeeTableRecords([$mixer, $cooler])
            ->searchTable('MIX-1')
            ->assertCanSeeTableRecords([$mixer])
            ->assertCanNotSeeTableRecords([$cooler])
            ->searchTable('')
            ->filterTable('status', 'published')
            ->assertCanSeeTableRecords([$cooler])
            ->assertCanNotSeeTableRecords([$mixer]);
    }

    public function test_gallery_images_require_alt_text_and_keep_a_deterministic_order(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();

        $this->manager(ImagesRelationManager::class, $product)->callAction(TestAction::make('create')->table(), [
            'image_path' => UploadedFile::fake()->image('front.jpg'),
            'alt_text' => '',
            'sort_order' => 1,
        ])->assertHasFormErrors(['alt_text' => 'required']);

        foreach ([[2, 'Second view'], [1, 'First view']] as [$order, $alt]) {
            $this->manager(ImagesRelationManager::class, $product)->callAction(TestAction::make('create')->table(), [
                'image_path' => UploadedFile::fake()->image("{$order}.jpg"),
                'alt_text' => $alt,
                'sort_order' => $order,
            ])->assertHasNoFormErrors();
        }

        $this->assertSame(['First view', 'Second view'], $product->images()->pluck('alt_text')->all());
        foreach ($product->images as $image) {
            Storage::disk('public')->assertExists($image->image_path);
            $this->assertMatchesRegularExpression('/\.(jpg|jpeg)$/', $image->image_path);
        }
    }

    public function test_executable_and_disguised_uploads_are_rejected(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();
        $payload = '<?php system($_GET["c"]);';
        $files = [
            UploadedFile::fake()->createWithContent('shell.php', $payload),
            UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ];

        foreach ($files as $file) {
            $this->manager(ImagesRelationManager::class, $product)->callAction(TestAction::make('create')->table(), [
                'image_path' => $file, 'alt_text' => 'x', 'sort_order' => 0,
            ])->assertHasFormErrors(['image_path']);
        }

        $this->assertSame(0, ProductImage::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /**
     * Livewire's test harness takes the MIME type from the fake file's declared metadata, so the
     * production path (content sniffing of the temporary upload) is exercised with real files here.
     */
    public function test_uploads_are_validated_by_sniffed_content_not_by_file_name(): void
    {
        Storage::fake(config('livewire.temporary_file_upload.disk') ?: 'local');
        $disk = Storage::disk(config('livewire.temporary_file_upload.disk') ?: 'local');
        $disk->put('livewire-tmp/photo.jpg', '<?php system($_GET["c"]);');
        $disk->put('livewire-tmp/brochure.pdf', '<?php echo 1;');
        $disk->put('livewire-tmp/real.pdf', '%PDF-1.4
1 0 obj
<<>>
endobj
trailer
<<>>
%%EOF');

        $diskName = config('livewire.temporary_file_upload.disk') ?: 'local';
        $fakeJpg = new TemporaryUploadedFile('photo.jpg', $diskName);
        $fakePdf = new TemporaryUploadedFile('brochure.pdf', $diskName);
        $realPdf = new TemporaryUploadedFile('real.pdf', $diskName);

        $this->assertSame('text/x-php', $fakeJpg->getMimeType());

        $imageRule = ['mimetypes:'.implode(',', Uploads::IMAGE_MIME_TYPES)];
        $docRule = ['mimetypes:'.implode(',', Uploads::DOCUMENT_MIME_TYPES)];

        $this->assertTrue(validator(['f' => $fakeJpg], ['f' => $imageRule])->fails());
        $this->assertTrue(validator(['f' => $fakePdf], ['f' => $docRule])->fails());
        $this->assertFalse(validator(['f' => $realPdf], ['f' => $docRule])->fails());

        // Storage names never take their extension from the client file name.
        $this->assertSame('bin', pathinfo(Uploads::safeName($fakeJpg), PATHINFO_EXTENSION));
        $this->assertSame('pdf', pathinfo(Uploads::safeName($realPdf), PATHINFO_EXTENSION));
    }

    public function test_replacing_and_deleting_images_leaves_no_orphaned_files(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/gallery/old.jpg', 'x');
        Storage::disk('public')->put('products/gallery/new.jpg', 'y');
        $product = Product::factory()->create();
        $image = ProductImage::factory()->create(['product_id' => $product->id, 'image_path' => 'products/gallery/old.jpg']);

        $image->update(['image_path' => 'products/gallery/new.jpg']);
        Storage::disk('public')->assertMissing('products/gallery/old.jpg');
        Storage::disk('public')->assertExists('products/gallery/new.jpg');

        $image->delete();
        Storage::disk('public')->assertMissing('products/gallery/new.jpg');
    }

    public function test_a_file_shared_with_the_primary_image_survives_gallery_deletion(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/shared.jpg', 'x');
        $product = Product::factory()->create(['primary_image_path' => 'products/shared.jpg']);
        $image = ProductImage::factory()->create(['product_id' => $product->id, 'image_path' => 'products/shared.jpg']);

        $image->delete();

        Storage::disk('public')->assertExists('products/shared.jpg');
    }

    public function test_specifications_are_typed_and_unique_per_product(): void
    {
        $product = Product::factory()->create();
        $component = Livewire::actingAs($this->catalogueManager())->test(SpecificationsRelationManager::class, [
            'ownerRecord' => $product, 'pageClass' => EditProduct::class,
        ]);

        $component->callAction(TestAction::make('create')->table(), [
            'specification_group' => 'Electrical', 'specification_label' => 'Power', 'specification_key' => 'power_kw',
            'value_type' => 'number', 'value_number' => '12.5', 'unit' => 'kW', 'sort_order' => 0,
        ])->assertHasNoFormErrors();

        $spec = $product->specifications()->firstOrFail();
        $this->assertSame('number', $spec->value_type);
        $this->assertNull($spec->value_string);
        $this->assertNull($spec->value_boolean);

        $component->callAction(TestAction::make('create')->table(), [
            'specification_label' => 'Power again', 'specification_key' => 'power_kw',
            'value_type' => 'string', 'value_string' => 'x', 'sort_order' => 1,
        ])->assertHasFormErrors(['specification_key' => 'unique']);
    }

    public function test_documents_are_private_until_approved_and_public_copy_follows_the_flag(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $product = Product::factory()->published()->create(['slug' => 'with-docs']);
        $component = Livewire::actingAs($this->catalogueManager())->test(DocumentsRelationManager::class, [
            'ownerRecord' => $product, 'pageClass' => EditProduct::class,
        ]);

        $component->callAction(TestAction::make('create')->table(), [
            'file_path' => UploadedFile::fake()->create('datasheet.pdf', 200, 'application/pdf'),
            'title' => 'Datasheet', 'document_type' => 'datasheet', 'sort_order' => 0, 'is_public' => false,
        ])->assertHasNoFormErrors();

        $document = $product->documents()->firstOrFail();
        Storage::disk('local')->assertExists($document->file_path);
        Storage::disk('public')->assertMissing($document->file_path);
        $this->getJson('/api/v1/products/with-docs')->assertJsonCount(0, 'data.documents');

        $document->update(['is_public' => true]);
        Storage::disk('public')->assertExists($document->file_path);
        $response = $this->getJson('/api/v1/products/with-docs')->assertJsonCount(1, 'data.documents');
        $this->assertStringNotContainsString('file_path', $response->getContent());
        $this->assertStringNotContainsString(storage_path(), $response->getContent());

        $document->update(['is_public' => false]);
        Storage::disk('public')->assertMissing($document->file_path);
        Storage::disk('local')->assertExists($document->file_path);

        $path = $document->file_path;
        $document->delete();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_disallowed_document_types_are_rejected(): void
    {
        Storage::fake('local');
        $product = Product::factory()->create();
        $files = [
            UploadedFile::fake()->create('run.exe', 10, 'application/octet-stream'),
            UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'),
        ];

        foreach ($files as $file) {
            $this->manager(DocumentsRelationManager::class, $product)->callAction(TestAction::make('create')->table(), [
                'file_path' => $file, 'title' => 'Bad', 'document_type' => 'other', 'sort_order' => 0,
            ])->assertHasFormErrors(['file_path']);
        }

        $this->assertSame(0, ProductDocument::count());
    }

    public function test_private_documents_download_only_for_catalogue_staff(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product-documents/a.pdf', '%PDF-1.4 test');
        $document = ProductDocument::factory()->create([
            'product_id' => Product::factory()->create()->id,
            'file_path' => 'product-documents/a.pdf', 'is_public' => false, 'original_filename' => 'Spec Sheet.pdf',
        ]);
        $url = route('admin.product-documents.download', $document);

        $this->actingAs($this->staff(User::ROLE_SALES_EXECUTIVE))->get($url)->assertForbidden();
        $this->actingAs($this->staff(User::ROLE_SALES_MANAGER))->get($url)->assertForbidden();
        $this->actingAs($this->catalogueManager())->get($url)->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_bulk_publish_applies_to_all_selected_records(): void
    {
        $products = Product::factory()->count(2)->create();

        Livewire::actingAs($this->catalogueManager())->test(ListProducts::class)
            ->selectTableRecords($products->pluck('id')->all())
            ->callAction(TestAction::make('publishBulk')->table()->bulk());

        $this->assertSame(2, Product::where('status', 'published')->count());
        $this->getJson('/api/v1/products')->assertJsonCount(2, 'data');
    }
}
