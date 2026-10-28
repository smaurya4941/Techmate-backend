<?php

namespace Tests\Feature\Database;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Models\ProductSpecification;
use App\Models\User;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Tests\DatabaseTestCase;

class CatalogueSchemaTest extends DatabaseTestCase
{
    public function test_users_default_to_least_privilege_and_role_is_not_mass_assignable(): void
    {
        $user = User::create(['name' => 'Staff', 'email' => 'staff@example.test', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => false])->fresh();

        $this->assertSame('sales_executive', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->isSuperAdmin());
        $this->assertNotSame('secret-pass', $user->password);
        $this->assertArrayNotHasKey('password', $user->toArray());
    }

    public function test_category_hierarchy_and_slug_uniqueness(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);

        $this->assertTrue($parent->children->contains($child));
        $this->assertTrue($child->parent->is($parent));

        $this->expectException(QueryException::class);
        Category::factory()->create(['slug' => $parent->slug]);
    }

    public function test_category_cannot_be_its_own_parent_or_descendant(): void
    {
        $a = Category::factory()->create();
        $b = Category::factory()->create(['parent_id' => $a->id]);

        try {
            $a->update(['parent_id' => $a->id]);
            $this->fail('Self-parenting was accepted.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        $a->update(['parent_id' => $b->id]);
    }

    public function test_category_with_children_or_products_cannot_be_hard_deleted(): void
    {
        $parent = Category::factory()->create();
        Category::factory()->create(['parent_id' => $parent->id]);

        try {
            $parent->forceDelete();
            $this->fail('Parent with children was deleted.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $withProduct = Category::factory()->create();
        Product::factory()->create(['category_id' => $withProduct->id]);
        $this->expectException(QueryException::class);
        $withProduct->forceDelete();
    }

    public function test_archiving_a_category_keeps_its_products(): void
    {
        $product = Product::factory()->create();
        $product->category->delete();

        $this->assertNotNull(Product::find($product->id));
        $this->assertTrue($product->fresh()->category->trashed());
    }

    public function test_product_needs_no_price_or_brand_and_defaults_to_draft(): void
    {
        $product = Product::factory()->create()->fresh();

        $this->assertNull($product->price);
        $this->assertNull($product->brand_id);
        $this->assertSame('draft', $product->status);
    }

    public function test_product_slug_and_sku_are_unique(): void
    {
        $product = Product::factory()->create();

        try {
            Product::factory()->create(['slug' => $product->slug]);
            $this->fail('Duplicate slug accepted.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(QueryException::class);
        Product::factory()->create(['sku' => $product->sku]);
    }

    public function test_status_is_not_mass_assignable_and_publish_sets_date(): void
    {
        $category = Category::factory()->create();
        $product = Product::create(['category_id' => $category->id, 'name' => 'X', 'slug' => 'x', 'sku' => 'X-1', 'status' => 'published'])->fresh();

        $this->assertSame('draft', $product->status);

        $product->publish()->save();
        $this->assertSame('published', $product->fresh()->status);
        $this->assertNotNull($product->fresh()->published_at);
    }

    public function test_only_published_products_in_active_categories_are_publicly_visible(): void
    {
        $visible = Product::factory()->published()->create();
        Product::factory()->create();
        Product::factory()->archived()->create();
        Product::factory()->published()->create(['category_id' => Category::factory()->inactive()]);
        Product::factory()->published()->create(['published_at' => now()->addDay()]);
        Product::factory()->published()->create()->delete();

        $this->assertSame([$visible->id], Product::publiclyVisible()->pluck('id')->all());
    }

    public function test_deleting_a_brand_keeps_products_without_a_brand(): void
    {
        $brand = Brand::factory()->create();
        $product = Product::factory()->create(['brand_id' => $brand->id]);

        $brand->forceDelete();

        $this->assertNull($product->fresh()->brand_id);
    }

    public function test_brand_rejects_unsafe_url_schemes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Brand::factory()->create(['website_url' => 'javascript:alert(1)']);
    }

    public function test_brand_accepts_https_url_and_null(): void
    {
        $this->assertNotNull(Brand::factory()->create(['website_url' => 'https://example.com'])->id);
        $this->assertNotNull(Brand::factory()->create(['website_url' => null])->id);
    }

    public function test_gallery_specs_and_documents_belong_to_product_and_cascade_on_hard_delete(): void
    {
        $product = Product::factory()->create();
        ProductImage::factory()->create(['product_id' => $product->id, 'sort_order' => 2]);
        $first = ProductImage::factory()->create(['product_id' => $product->id, 'sort_order' => 1]);
        ProductSpecification::factory()->number('1200', 'mm')->create(['product_id' => $product->id]);
        ProductDocument::factory()->create(['product_id' => $product->id]);
        ProductDocument::factory()->public()->create(['product_id' => $product->id]);

        $this->assertTrue($product->images->first()->is($first));
        $this->assertCount(1, $product->specifications);
        $this->assertSame('mm', $product->specifications->first()->unit);
        $this->assertCount(2, $product->documents);
        $this->assertCount(1, $product->publicDocuments);

        $product->forceDelete();

        $this->assertSame(0, ProductImage::count() + ProductSpecification::count() + ProductDocument::count());
    }

    public function test_soft_deleting_a_product_keeps_its_children(): void
    {
        $product = Product::factory()->create();
        ProductImage::factory()->create(['product_id' => $product->id]);

        $product->delete();

        $this->assertSame(1, ProductImage::count());
    }

    public function test_specification_key_is_unique_per_product(): void
    {
        $product = Product::factory()->create();
        ProductSpecification::factory()->create(['product_id' => $product->id, 'specification_key' => 'power']);

        $this->expectException(QueryException::class);
        ProductSpecification::factory()->create(['product_id' => $product->id, 'specification_key' => 'power', 'specification_group' => 'Electrical']);
    }

    public function test_specification_value_must_match_its_type(): void
    {
        $product = Product::factory()->create();

        foreach ([
            ['value_type' => 'number', 'value_string' => 'oops', 'value_number' => null],
            ['value_type' => 'string', 'value_string' => 'a', 'value_boolean' => true],
            ['value_type' => 'boolean', 'value_string' => null],
            ['value_type' => 'weird'],
        ] as $override) {
            try {
                ProductSpecification::factory()->create(['product_id' => $product->id] + $override);
                $this->fail('Contradictory specification accepted: '.json_encode($override));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $spec = ProductSpecification::factory()->boolean(true)->create(['product_id' => $product->id]);
        $this->assertTrue($spec->fresh()->value());
    }

    public function test_money_columns_are_exact_decimals(): void
    {
        $product = Product::factory()->priced('1234567890.12', 'USD')->create();

        $this->assertSame('1234567890.12', $product->fresh()->price);
    }

    public function test_database_rejects_invalid_catalogue_values(): void
    {
        $this->requireCheckConstraints();
        $product = Product::factory()->create();

        $bad = [
            fn () => Product::factory()->create(['price' => '-1.00', 'currency_code' => 'USD']),
            fn () => Product::factory()->create(['price' => '10.00', 'currency_code' => null]),
            fn () => $product->newQuery()->whereKey($product->id)->update(['status' => 'bogus']),
            fn () => ProductSpecification::query()->insert([
                'product_id' => $product->id, 'specification_key' => 'k', 'specification_label' => 'K',
                'value_type' => 'number', 'value_string' => 'x', 'value_number' => 1, 'sort_order' => 0,
            ]),
            fn () => ProductDocument::query()->insert([
                'product_id' => $product->id, 'title' => 't', 'document_type' => 'bogus', 'file_path' => 'p', 'sort_order' => 0,
            ]),
            fn () => User::factory()->create(['role' => 'superuser']),
        ];

        foreach ($bad as $i => $attempt) {
            try {
                $attempt();
                $this->fail("Case {$i} was accepted by the database.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
