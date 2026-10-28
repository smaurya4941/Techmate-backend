<?php

namespace Tests\Feature\Console;

use App\Models\Category;
use App\Models\Product;
use Tests\DatabaseTestCase;

class ImportCatalogueTest extends DatabaseTestCase
{
    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cat').'.csv';
        file_put_contents($path, $body);

        return $path;
    }

    public function test_imports_as_drafts_idempotently_and_publishes_on_request(): void
    {
        $file = $this->csv("Name,Model Number,Category,Subcategory,Brand,Price,Currency\nTest Oven,T-1,Ovens,Combi,Acme,,\nTest Mixer,T-2,Bakery,,,100.50,INR\n");

        $this->artisan('catalogue:import', ['file' => $file])->assertSuccessful();

        $this->assertSame(2, Product::count());
        $this->assertSame('draft', Product::where('sku', 'T-1')->value('status'));
        $this->assertNull(Product::where('sku', 'T-1')->value('price'));
        $this->assertSame('INR', Product::where('sku', 'T-2')->value('currency_code'));
        $this->assertNotNull(Category::where('slug', 'ovens-combi')->value('parent_id'));
        $this->getJson('/api/v1/products')->assertJsonPath('meta.total', 0);

        $this->artisan('catalogue:import', ['file' => $file, '--publish' => true])->assertSuccessful();

        $this->assertSame(2, Product::count());
        $this->getJson('/api/v1/products')->assertJsonPath('meta.total', 2);
    }

    public function test_invalid_rows_abort_the_whole_import(): void
    {
        $file = $this->csv("name,model_number,category,price,currency\nGood,G-1,Ovens,,\nBad,B-1,Ovens,-5,INR\n");

        $this->artisan('catalogue:import', ['file' => $file])->assertFailed();

        $this->assertSame(0, Product::count());
    }

    public function test_dry_run_writes_nothing_and_missing_columns_fail(): void
    {
        $this->artisan('catalogue:import', ['file' => $this->csv("name,model_number,category\nA,A-1,Ovens\n"), '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, Product::count());

        $this->artisan('catalogue:import', ['file' => $this->csv("name,category\nA,Ovens\n")])->assertFailed();
    }
}
