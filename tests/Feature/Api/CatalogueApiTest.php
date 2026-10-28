<?php

namespace Tests\Feature\Api;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Models\ProductSpecification;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

class CatalogueApiTest extends DatabaseTestCase
{
    private function published(array $attributes = []): Product
    {
        return Product::factory()->published()->create($attributes);
    }

    public function test_categories_return_active_roots_with_bounded_children(): void
    {
        $root = Category::factory()->create(['name' => 'Ovens', 'slug' => 'ovens']);
        $child = Category::factory()->create(['parent_id' => $root->id, 'slug' => 'combi-ovens']);
        Category::factory()->create(['parent_id' => $child->id, 'slug' => 'grandchild']);
        Category::factory()->inactive()->create(['slug' => 'hidden']);
        Category::factory()->create(['slug' => 'deleted'])->delete();
        Category::factory()->inactive()->create(['parent_id' => $root->id, 'slug' => 'hidden-child']);
        $this->published(['category_id' => $root->id]);
        $this->published(['category_id' => $root->id])->archive()->save();

        $response = $this->getJson('/api/v1/categories')->assertOk()
            ->assertJsonPath('message', 'Categories retrieved successfully.')
            ->assertJsonPath('meta.total', 1);

        $this->assertSame(['ovens'], array_column($response->json('data'), 'slug'));
        $this->assertSame(['combi-ovens'], array_column($response->json('data.0.children'), 'slug'));
        $this->assertSame(1, $response->json('data.0.product_count'));
        $this->assertArrayNotHasKey('children', $response->json('data.0.children.0'));
        $this->assertArrayNotHasKey('created_at', $response->json('data.0'));
        $this->assertArrayNotHasKey('meta_title', $response->json('data.0'));
    }

    public function test_brands_return_active_only_with_pagination_meta(): void
    {
        Brand::factory()->create(['slug' => 'active-brand']);
        Brand::factory()->create(['slug' => 'inactive-brand', 'is_active' => false]);
        Brand::factory()->create(['slug' => 'deleted-brand'])->delete();

        $this->getJson('/api/v1/brands')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'active-brand')
            ->assertJsonPath('meta', ['current_page' => 1, 'per_page' => 24, 'total' => 1, 'last_page' => 1])
            ->assertJsonMissingPath('data.0.is_active');
    }

    public function test_only_published_visible_products_are_listed(): void
    {
        $visible = $this->published(['slug' => 'visible']);
        Product::factory()->create(['slug' => 'draft']);
        Product::factory()->archived()->create(['slug' => 'archived']);
        $this->published(['slug' => 'deleted'])->delete();
        Product::factory()->published()->create(['slug' => 'future', 'published_at' => now()->addDay()]);
        $inactive = Category::factory()->inactive()->create();
        $this->published(['slug' => 'in-inactive-category', 'category_id' => $inactive->id]);
        $trashed = Category::factory()->create();
        $this->published(['slug' => 'in-deleted-category', 'category_id' => $trashed->id]);
        $trashed->delete();

        $response = $this->getJson('/api/v1/products')->assertOk()
            ->assertJsonPath('message', 'Products retrieved successfully.')
            ->assertJsonPath('meta.total', 1);

        $this->assertSame([$visible->slug], array_column($response->json('data'), 'slug'));
    }

    public function test_empty_listing_has_stable_shape(): void
    {
        $this->getJson('/api/v1/products')->assertOk()->assertExactJson([
            'message' => 'Products retrieved successfully.',
            'data' => [],
            'meta' => ['current_page' => 1, 'per_page' => 12, 'total' => 0, 'last_page' => 1],
        ]);
    }

    public function test_pagination_and_per_page_bounds(): void
    {
        Product::factory()->published()->count(5)->create();

        $this->getJson('/api/v1/products?per_page=2&page=3')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.per_page', 2);

        foreach (['per_page=0', 'per_page=101', 'per_page=abc', 'page=0', 'page=x'] as $query) {
            $this->getJson("/api/v1/products?{$query}")->assertStatus(422)->assertJsonStructure(['message', 'errors', 'request_id']);
        }
    }

    public function test_search_is_literal_and_covers_approved_fields(): void
    {
        $brand = Brand::factory()->create(['name' => 'Rationale']);
        $this->published(['name' => 'Combi Oven', 'sku' => 'CO-100']);
        $this->published(['name' => 'Deck Oven', 'short_description' => 'Stone deck baking']);
        $this->published(['name' => 'Mixer', 'brand_id' => $brand->id]);
        $this->published(['name' => '100% Steel Table', 'sku' => 'ST_1']);
        $this->published(['name' => 'Other 100x Table', 'sku' => 'STX1']);

        $names = fn (string $q) => collect($this->getJson('/api/v1/products?search='.urlencode($q))->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Combi Oven'], $names('CO-100'));
        $this->assertSame(['Combi Oven', 'Deck Oven'], $names('oven'));
        $this->assertSame(['Deck Oven'], $names('stone deck'));
        $this->assertSame(['Mixer'], $names('rationale'));
        // Wildcards are literal: "100%" must not match "100x", "ST_1" must not match "STX1".
        $this->assertSame(['100% Steel Table'], $names('100%'));
        $this->assertSame(['100% Steel Table'], $names('ST_1'));
        $this->assertSame([], $names('no-such-thing'));
    }

    public function test_search_does_not_match_hidden_brand_names(): void
    {
        $hidden = Brand::factory()->create(['name' => 'Secretbrand', 'is_active' => false]);
        $this->published(['name' => 'Widget', 'brand_id' => $hidden->id]);

        $this->getJson('/api/v1/products?search=secretbrand')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/products')->assertJsonPath('data.0.brand', null);
    }

    public function test_category_brand_and_featured_filters(): void
    {
        $ovens = Category::factory()->create(['slug' => 'ovens']);
        $sub = Category::factory()->create(['slug' => 'combi', 'parent_id' => $ovens->id]);
        $fridges = Category::factory()->create(['slug' => 'fridges']);
        $brand = Brand::factory()->create(['slug' => 'acme']);
        $this->published(['slug' => 'p-oven', 'category_id' => $ovens->id, 'brand_id' => $brand->id, 'is_featured' => true]);
        $this->published(['slug' => 'p-combi', 'category_id' => $sub->id]);
        $this->published(['slug' => 'p-fridge', 'category_id' => $fridges->id]);

        $slugs = fn (string $q) => collect($this->getJson("/api/v1/products?{$q}")->assertOk()->json('data'))->pluck('slug')->sort()->values()->all();

        $this->assertSame(['p-combi', 'p-oven'], $slugs('category=ovens'));
        $this->assertSame(['p-combi'], $slugs('category=combi'));
        $this->assertSame([], $slugs('category=unknown'));
        $this->assertSame(['p-oven'], $slugs('brand=acme'));
        $this->assertSame(['p-oven'], $slugs('featured=true'));
        $this->assertSame(['p-combi', 'p-fridge'], $slugs('featured=0'));
        $this->getJson('/api/v1/products?featured=maybe')->assertStatus(422);
        $this->getJson('/api/v1/products?category=Bad%20Slug!')->assertStatus(422);
    }

    public function test_sorting_is_allowlisted_and_deterministic(): void
    {
        $this->published(['name' => 'B', 'slug' => 'b', 'sort_order' => 1]);
        $this->published(['name' => 'A', 'slug' => 'a', 'sort_order' => 1]);
        $this->published(['name' => 'C', 'slug' => 'c', 'sort_order' => 0]);

        $slugs = fn (string $q) => array_column($this->getJson("/api/v1/products?{$q}")->assertOk()->json('data'), 'slug');

        $this->assertSame(['c', 'b', 'a'], $slugs('')); // sort_order asc, id tie-breaker
        $this->assertSame(['a', 'b', 'c'], $slugs('sort_by=name'));
        $this->assertSame(['c', 'b', 'a'], $slugs('sort_by=name&sort_direction=desc'));

        $this->getJson('/api/v1/products?sort_by=price;DROP TABLE products')->assertStatus(422)->assertJsonValidationErrors('sort_by');
        $this->getJson('/api/v1/products?sort_by=cost_price')->assertStatus(422);
        $this->getJson('/api/v1/products?sort_direction=sideways')->assertStatus(422);
    }

    public function test_product_detail_by_slug_returns_public_data_only(): void
    {
        $brand = Brand::factory()->create();
        $product = $this->published([
            'slug' => 'sample', 'brand_id' => $brand->id, 'description' => 'Long text',
            'price' => '1500.00', 'currency_code' => 'INR',
        ]);
        ProductImage::create(['product_id' => $product->id, 'image_path' => 'img/second.jpg', 'sort_order' => 2]);
        ProductImage::create(['product_id' => $product->id, 'image_path' => 'img/first.jpg', 'sort_order' => 1, 'alt_text' => 'Front']);
        ProductSpecification::factory()->for($product)->create(['specification_key' => 'k1', 'specification_label' => 'Colour', 'sort_order' => 1]);
        ProductSpecification::factory()->for($product)->number('12.5', 'kW')->create(['specification_key' => 'power', 'specification_label' => 'Power', 'specification_group' => 'Electrical', 'sort_order' => 2]);
        ProductDocument::factory()->for($product)->public()->create(['title' => 'Public sheet', 'file_path' => 'documents/public.pdf']);
        ProductDocument::factory()->for($product)->create(['title' => 'Private sheet', 'file_path' => 'documents/private-secret.pdf']);

        $response = $this->getJson('/api/v1/products/sample')->assertOk()
            ->assertJsonPath('message', 'Product retrieved successfully.')
            ->assertJsonPath('data.slug', 'sample')
            ->assertJsonPath('data.price', '1500.00')
            ->assertJsonPath('data.brand.slug', $brand->slug)
            ->assertJsonPath('data.images.0.alt_text', 'Front')
            ->assertJsonPath('data.specifications.0.group', 'General')
            ->assertJsonPath('data.specifications.1.items.0.value', 12.5)
            ->assertJsonCount(1, 'data.documents')
            ->assertJsonPath('data.documents.0.title', 'Public sheet');

        $this->assertStringEndsWith('img/first.jpg', $response->json('data.images.0.url'));

        $body = $response->getContent();
        foreach (['private-secret', 'Private sheet', 'created_by', 'updated_by', 'file_path', 'image_path', '"status"', 'sort_order'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    public function test_price_stays_null_for_quote_only_products(): void
    {
        $this->published(['slug' => 'quote-only']);

        $this->getJson('/api/v1/products/quote-only')->assertOk()->assertJsonPath('data.price', null);
    }

    public function test_missing_unpublished_and_deleted_products_return_identical_404(): void
    {
        Product::factory()->create(['slug' => 'draft']);
        $this->published(['slug' => 'gone'])->delete();

        $missing = $this->getJson('/api/v1/products/does-not-exist')->assertNotFound();
        $draft = $this->getJson('/api/v1/products/draft')->assertNotFound();
        $this->getJson('/api/v1/products/gone')->assertNotFound();

        $this->assertSame($missing->json('message'), $draft->json('message'));
        $this->assertSame('Resource not found.', $draft->json('message'));
    }

    public function test_category_products_endpoint_filters_and_resolves_active_category_only(): void
    {
        $parent = Category::factory()->create(['slug' => 'ovens']);
        $child = Category::factory()->create(['slug' => 'combi', 'parent_id' => $parent->id]);
        $other = Category::factory()->create(['slug' => 'fridges']);
        $brand = Brand::factory()->create(['slug' => 'acme']);
        $this->published(['slug' => 'p1', 'category_id' => $parent->id, 'brand_id' => $brand->id, 'name' => 'Alpha']);
        $this->published(['slug' => 'p2', 'category_id' => $child->id, 'name' => 'Beta']);
        $this->published(['slug' => 'p3', 'category_id' => $other->id]);
        Product::factory()->create(['slug' => 'draft', 'category_id' => $parent->id]);
        Category::factory()->inactive()->create(['slug' => 'off']);

        $slugs = fn (string $q) => collect($this->getJson("/api/v1/categories/ovens/products{$q}")->assertOk()->json('data'))->pluck('slug')->sort()->values()->all();

        $this->assertSame(['p1', 'p2'], $slugs(''));
        $this->assertSame(['p1'], $slugs('?brand=acme'));
        $this->assertSame(['p2'], $slugs('?search=beta'));
        $this->assertSame(['p1', 'p2'], $slugs('?category=fridges')); // path decides scope
        $this->getJson('/api/v1/categories/ovens/products?sort_by=bogus')->assertStatus(422);
        $this->getJson('/api/v1/categories/off/products')->assertNotFound();
        $this->getJson('/api/v1/categories/nope/products')->assertNotFound();
    }

    public function test_listing_query_count_does_not_grow_with_rows(): void
    {
        $brand = Brand::factory()->create();
        Product::factory()->published()->count(3)->create(['brand_id' => $brand->id]);

        DB::enableQueryLog();
        $this->getJson('/api/v1/products')->assertOk();
        $small = count(DB::getQueryLog());

        Product::factory()->published()->count(20)->create(['brand_id' => $brand->id]);
        DB::flushQueryLog();
        $this->getJson('/api/v1/products?per_page=50')->assertOk()->assertJsonCount(23, 'data');
        $large = count(DB::getQueryLog());

        $this->assertSame($small, $large, 'Listing must not issue per-row queries.');
        $this->assertLessThanOrEqual(5, $large);
    }

    public function test_detail_query_count_is_constant(): void
    {
        $product = $this->published(['slug' => 'q']);
        ProductImage::create(['product_id' => $product->id, 'image_path' => 'a.jpg']);
        ProductSpecification::factory()->count(5)->for($product)->create();
        ProductDocument::factory()->count(3)->for($product)->public()->create();

        DB::enableQueryLog();
        $this->getJson('/api/v1/products/q')->assertOk();

        $this->assertLessThanOrEqual(6, count(DB::getQueryLog()));
    }

    public function test_catalogue_routes_are_read_only(): void
    {
        foreach (['/api/v1/products', '/api/v1/categories', '/api/v1/brands', '/api/v1/products/x'] as $uri) {
            $this->postJson($uri, [])->assertStatus(405);
            $this->deleteJson($uri)->assertStatus(405);
        }
    }
}
