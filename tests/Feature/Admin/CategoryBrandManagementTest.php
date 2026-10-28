<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Brands\Pages\CreateBrand;
use App\Filament\Resources\Brands\Pages\EditBrand;
use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class CategoryBrandManagementTest extends AdminTestCase
{
    public function test_a_category_can_be_created_with_a_parent_and_image(): void
    {
        Storage::fake('public');
        $parent = Category::factory()->create();

        Livewire::actingAs($this->catalogueManager())->test(CreateCategory::class)
            ->fillForm([
                'name' => 'Combi Ovens', 'slug' => 'combi-ovens', 'parent_id' => $parent->id,
                'is_active' => true, 'sort_order' => 3, 'image_path' => UploadedFile::fake()->image('c.png'),
            ])
            ->call('create')->assertHasNoFormErrors();

        $category = Category::where('slug', 'combi-ovens')->firstOrFail();
        $this->assertSame($parent->id, $category->parent_id);
        Storage::disk('public')->assertExists($category->image_path);
    }

    public function test_a_category_cannot_be_its_own_parent_or_form_a_loop(): void
    {
        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $manager = $this->catalogueManager();

        // Self-parenting: the option is not offered, and a tampered value is rejected server-side.
        Livewire::actingAs($manager)->test(EditCategory::class, ['record' => $root->getKey()])
            ->fillForm(['parent_id' => $root->id])->call('save')->assertHasFormErrors(['parent_id']);

        // A parent that already has children cannot be moved beneath its own child.
        Livewire::actingAs($manager)->test(EditCategory::class, ['record' => $root->getKey()])
            ->fillForm(['parent_id' => $child->id])->call('save')->assertHasFormErrors(['parent_id']);

        $this->assertNull($root->fresh()->parent_id);
    }

    public function test_nesting_is_limited_to_two_levels(): void
    {
        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);

        Livewire::actingAs($this->catalogueManager())->test(CreateCategory::class)
            ->fillForm(['name' => 'Too Deep', 'slug' => 'too-deep', 'parent_id' => $child->id, 'is_active' => true, 'sort_order' => 0])
            ->call('create')->assertHasFormErrors(['parent_id']);

        $this->assertDatabaseMissing('categories', ['slug' => 'too-deep']);
    }

    public function test_the_model_also_refuses_a_cyclic_parent(): void
    {
        $a = Category::factory()->create();
        $b = Category::factory()->create(['parent_id' => $a->id]);

        $this->expectException(\InvalidArgumentException::class);
        $a->update(['parent_id' => $b->id]);
    }

    public function test_duplicate_category_and_brand_slugs_are_rejected(): void
    {
        $category = Category::factory()->create(['slug' => 'taken']);
        Brand::factory()->create(['slug' => 'taken-brand']);
        $manager = $this->catalogueManager();

        Livewire::actingAs($manager)->test(CreateCategory::class)
            ->fillForm(['name' => 'Other', 'slug' => 'taken', 'is_active' => true, 'sort_order' => 0])
            ->call('create')->assertHasFormErrors(['slug' => 'unique']);

        Livewire::actingAs($manager)->test(CreateBrand::class)
            ->fillForm(['name' => 'Other', 'slug' => 'taken-brand', 'is_active' => true, 'sort_order' => 0])
            ->call('create')->assertHasFormErrors(['slug' => 'unique']);

        // Re-saving a record under its own slug is fine.
        Livewire::actingAs($manager)->test(EditCategory::class, ['record' => $category->getKey()])
            ->fillForm(['name' => 'Renamed'])->call('save')->assertHasNoFormErrors();
    }

    public function test_brand_urls_must_be_http_or_https(): void
    {
        $manager = $this->catalogueManager();

        foreach (['javascript:alert(1)', 'ftp://example.test', 'data:text/html,x'] as $url) {
            Livewire::actingAs($manager)->test(CreateBrand::class)
                ->fillForm(['name' => 'Brand', 'slug' => 'brand-x', 'website_url' => $url, 'is_active' => true, 'sort_order' => 0])
                ->call('create')->assertHasFormErrors(['website_url']);
        }

        Livewire::actingAs($manager)->test(CreateBrand::class)
            ->fillForm(['name' => 'Brand', 'slug' => 'brand-x', 'website_url' => 'https://brand.example.test', 'is_active' => true, 'sort_order' => 0])
            ->call('create')->assertHasNoFormErrors();
    }

    public function test_deactivating_a_brand_keeps_product_associations_and_hides_it_publicly(): void
    {
        $brand = Brand::factory()->create();
        $product = Product::factory()->published()->create(['brand_id' => $brand->id, 'slug' => 'branded']);

        Livewire::actingAs($this->catalogueManager())->test(ListBrands::class)
            ->callAction(TestAction::make('toggleActive')->table($brand));

        $this->assertFalse($brand->fresh()->is_active);
        $this->assertSame($brand->id, $product->fresh()->brand_id);
        $this->getJson('/api/v1/brands')->assertJsonCount(0, 'data');
    }

    public function test_deactivating_a_category_hides_it_and_its_products_publicly(): void
    {
        $category = Category::factory()->create(['slug' => 'ovens']);
        Product::factory()->published()->create(['category_id' => $category->id, 'slug' => 'oven-1']);
        $this->getJson('/api/v1/products/oven-1')->assertOk();

        Livewire::actingAs($this->catalogueManager())->test(ListCategories::class)
            ->callAction(TestAction::make('toggleActive')->table($category));

        $this->getJson('/api/v1/products/oven-1')->assertNotFound();
        $this->getJson('/api/v1/categories')->assertJsonCount(0, 'data');
    }

    public function test_categories_with_products_cannot_be_deleted_even_by_a_super_admin(): void
    {
        $category = Category::factory()->create();
        Product::factory()->create(['category_id' => $category->id]);

        Livewire::actingAs($this->superAdmin())->test(EditCategory::class, ['record' => $category->getKey()])
            ->assertActionHidden('delete');

        $this->assertNotSoftDeleted($category);
    }

    public function test_catalogue_managers_cannot_delete_brands_but_super_admins_can_archive_them(): void
    {
        $brand = Brand::factory()->create();

        Livewire::actingAs($this->catalogueManager())->test(EditBrand::class, ['record' => $brand->getKey()])
            ->assertActionHidden('delete');

        Livewire::actingAs($this->superAdmin())->test(EditBrand::class, ['record' => $brand->getKey()])
            ->callAction('delete');

        $this->assertSoftDeleted($brand);
    }

    public function test_replacing_a_logo_removes_the_old_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('brands/logos/old.png', 'x');
        $brand = Brand::factory()->create(['logo_path' => 'brands/logos/old.png']);

        $brand->update(['logo_path' => null]);

        Storage::disk('public')->assertMissing('brands/logos/old.png');
    }
}
