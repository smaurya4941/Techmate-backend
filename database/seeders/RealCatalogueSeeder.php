<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSpecification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Techmate's real catalogue structure and first products, so the admin panel starts
 * populated and everything can be edited there afterwards.
 *
 * - Categories and sub-categories, and the 11 authorised brands, were supplied by Techmate.
 * - The 18 UNOX products come from Techmate's previous website (prices are indicative INR).
 *
 * Idempotent and non-destructive: rows are matched by slug/SKU and created only when missing,
 * so edits made in the admin panel are never overwritten by re-running this seeder.
 */
class RealCatalogueSeeder extends Seeder
{
    /** @var array<string, array{name: string, subs: array<string, string>}> */
    private const TAXONOMY = [
        'bakery-baking' => ['name' => 'Bakery & Baking', 'subs' => [
            'bakery-ovens' => 'Bakery Ovens', 'convection-ovens' => 'Convection Ovens', 'deck-ovens' => 'Deck Ovens',
            'bakery-combi-ovens' => 'Combi Ovens', 'rack-ovens' => 'Rack Ovens', 'spiral-dough-mixers' => 'Spiral Dough Mixers',
            'planetary-mixers' => 'Planetary Mixers', 'proofers' => 'Proofers / Proving Chambers',
            'dough-sheeters' => 'Dough Sheeters', 'bread-slicers' => 'Bread Slicers',
        ]],
        'cooking' => ['name' => 'Cooking Equipment', 'subs' => [
            'cooking-combi-ovens' => 'Combi Ovens', 'cooking-ranges' => 'Cooking Ranges', 'fryers' => 'Fryers',
            'griddles' => 'Griddles', 'induction-cooking' => 'Induction Cooking', 'salamanders' => 'Salamanders',
        ]],
        'food-preparation' => ['name' => 'Food Preparation', 'subs' => [
            'food-processors' => 'Food Processors', 'vegetable-preparation' => 'Vegetable Preparation',
            'stick-blenders' => 'Stick Blenders', 'food-prep-mixers' => 'Mixers', 'choppers' => 'Choppers',
        ]],
        'dishwashing' => ['name' => 'Dishwashing', 'subs' => [
            'undercounter-dishwashers' => 'Undercounter Dishwashers', 'hood-type-dishwashers' => 'Hood Type Dishwashers',
            'rack-conveyor-dishwashers' => 'Rack Conveyor Dishwashers', 'flight-type-dishwashers' => 'Flight Type Dishwashers',
            'glasswashers' => 'Glasswashers',
        ]],
        'refrigeration' => ['name' => 'Refrigeration', 'subs' => [
            'upright-refrigerators' => 'Upright Refrigerators', 'freezers' => 'Freezers',
            'under-counter-refrigeration' => 'Under Counter Refrigeration', 'preparation-counters' => 'Preparation Counters',
            'blast-chillers-freezers' => 'Blast Chillers & Freezers',
        ]],
        'hot-holding' => ['name' => 'Hot Holding', 'subs' => [
            'holding-cabinets' => 'Holding Cabinets', 'heated-shelves' => 'Heated Shelves', 'food-warmers' => 'Food Warmers',
        ]],
        'beverage' => ['name' => 'Beverage Equipment', 'subs' => [
            'coffee-machines' => 'Coffee Machines', 'coffee-grinders' => 'Coffee Grinders', 'juice-equipment' => 'Juice Equipment',
        ]],
        'kitchen-equipment' => ['name' => 'Kitchen Equipment', 'subs' => [
            'stainless-steel-equipment' => 'Stainless Steel Equipment', 'work-tables' => 'Work Tables', 'sinks' => 'Sinks',
            'storage-racks' => 'Storage Racks', 'kitchen-accessories' => 'Kitchen Accessories',
        ]],
    ];

    /** Authorised brands: slug => [name, positioning]. */
    private const BRANDS = [
        'unox' => ['UNOX', 'Commercial ovens, combi ovens, bakery ovens and professional cooking/baking solutions.'],
        'electrolux-professional' => ['Electrolux Professional', 'Professional cooking, dishwashing, refrigeration and foodservice solutions.'],
        'alto-shaam' => ['Alto-Shaam', 'Cooking, hot holding and food holding solutions.'],
        'rational' => ['Rational', 'Professional combi cooking systems.'],
        'spar' => ['SPAR', 'Planetary mixers, bakery and food preparation equipment.'],
        'hatco' => ['Hatco', 'Food holding, warming and serving equipment.'],
        'dynamic' => ['Dynamic', 'Professional hand mixers, stick blenders and food preparation.'],
        'robot-coupe' => ['Robot Coupe', 'Food processors and vegetable preparation equipment.'],
        'veetsan' => ['Veetsan', 'Commercial dishwashing and kitchen equipment.'],
        'homat' => ['Homat', 'Professional kitchen and foodservice equipment.'],
        'hongling' => ['Hongling', 'Bakery and food processing equipment.'],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $categoryIds = $this->seedCategories();
            $brandIds = $this->seedBrands();
            $this->seedProducts($categoryIds, $brandIds);
        });
    }

    /** @return array<string, int> slug => id */
    private function seedCategories(): array
    {
        $ids = [];
        $order = 0;

        foreach (self::TAXONOMY as $slug => $data) {
            $parent = Category::firstOrCreate(['slug' => $slug], [
                'name' => $data['name'],
                'description' => implode(', ', array_values($data['subs'])),
                'is_active' => true,
                'sort_order' => ++$order,
            ]);
            $ids[$slug] = $parent->id;

            $subOrder = 0;
            foreach ($data['subs'] as $subSlug => $subName) {
                $ids[$subSlug] = Category::firstOrCreate(['slug' => $subSlug], [
                    'parent_id' => $parent->id,
                    'name' => $subName,
                    'is_active' => true,
                    'sort_order' => ++$subOrder,
                ])->id;
            }
        }

        return $ids;
    }

    /** @return array<string, int> slug => id */
    private function seedBrands(): array
    {
        $ids = [];
        $order = 0;

        foreach (self::BRANDS as $slug => [$name, $positioning]) {
            $ids[$slug] = Brand::firstOrCreate(['slug' => $slug], [
                'name' => $name,
                'description' => $positioning,
                'is_active' => true,
                'sort_order' => ++$order,
            ])->id;
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $categoryIds
     * @param  array<string, int>  $brandIds
     */
    private function seedProducts(array $categoryIds, array $brandIds): void
    {
        $rows = json_decode((string) file_get_contents(__DIR__.'/data/unox-products.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach ($rows as $index => $row) {
            if (Product::withTrashed()->where('sku', $row['sku'])->exists()) {
                continue;
            }

            $product = new Product([
                'category_id' => $categoryIds[$row['subCategory'] ?? $row['category']],
                'brand_id' => $brandIds[$row['brand']],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'sku' => $row['sku'],
                'short_description' => $row['short'],
                'description' => $row['description'],
                'price' => $row['price'],
                'currency_code' => 'INR',
                'power_source' => $row['powerSource'],
                'key_highlights' => $row['highlights'],
                'sort_order' => $index + 1,
            ]);
            $product->publish()->save();

            foreach ($row['specs'] as $i => $spec) {
                ProductSpecification::create([
                    'product_id' => $product->id,
                    'specification_group' => $spec['group'],
                    'specification_key' => Str::slug($spec['label'], '_'),
                    'specification_label' => $spec['label'],
                    'value_type' => ProductSpecification::TYPE_STRING,
                    'value_string' => $spec['value'],
                    'unit' => $spec['unit'],
                    'sort_order' => $i + 1,
                ]);
            }
        }
    }
}
