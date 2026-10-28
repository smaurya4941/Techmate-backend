<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Lead;
use App\Models\LeadProduct;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Relationship-verification data for local development only. Every row is
 * labelled "Sample"; no real products, prices, specifications or customers.
 * Idempotent: keyed on slug/sku/email, safe to run repeatedly.
 */
class DevelopmentSampleSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DevelopmentSampleSeeder runs only in local/testing environments.');
        }

        DB::transaction(function () {
            // The password is generated per run and never printed; reset it with `php artisan tinker` if needed.
            User::firstOrCreate(['email' => 'sample-admin@example.test'], [
                'name' => 'Sample Admin',
                'password' => bin2hex(random_bytes(16)),
            ])->forceFill(['role' => User::ROLE_SUPER_ADMIN, 'is_active' => true])->save();

            $category = Category::firstOrCreate(['slug' => 'sample-category'], ['name' => 'Sample Category']);
            Category::firstOrCreate(['slug' => 'sample-subcategory'], [
                'name' => 'Sample Subcategory', 'parent_id' => $category->id,
            ]);
            $brand = Brand::firstOrCreate(['slug' => 'sample-brand'], ['name' => 'Sample Brand']);

            $product = Product::firstOrCreate(['sku' => 'SAMPLE-0001'], [
                'category_id' => $category->id,
                'brand_id' => $brand->id,
                'name' => 'Sample Product',
                'slug' => 'sample-product',
            ]);
            $product->publish()->save();

            ProductSpecification::firstOrCreate(
                ['product_id' => $product->id, 'specification_key' => 'sample_attribute'],
                [
                    'specification_label' => 'Sample attribute', 'value_type' => 'string',
                    'value_string' => 'sample value',
                ]
            );

            $lead = Lead::firstOrCreate(['email' => 'sample-lead@example.test'], [
                'customer_name' => 'Sample Contact', 'lead_type' => 'product',
            ]);
            LeadProduct::firstOrCreate(['lead_id' => $lead->id, 'product_id' => $product->id], [
                'product_name_snapshot' => $product->name, 'product_code_snapshot' => $product->sku,
            ]);
        });
    }
}
