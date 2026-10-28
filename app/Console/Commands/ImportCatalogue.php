<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Loads the Phase 0 product spreadsheet (exported as CSV) into the catalogue.
 *
 * Required columns: name, model_number, category
 * Optional columns: subcategory, brand, short_description, description, price,
 *                   currency, slug, featured
 * Idempotent: products are matched on model_number (sku). Rows are imported as
 * drafts unless --publish is given, so nothing goes live unreviewed.
 */
class ImportCatalogue extends Command
{
    protected $signature = 'catalogue:import {file : Path to the CSV file} {--publish : Publish imported products} {--dry-run : Validate and report without writing}';

    protected $description = 'Import products, categories and brands from a CSV file';

    public function handle(): int
    {
        $path = $this->argument('file');

        if (! is_readable($path) || ($handle = fopen($path, 'r')) === false) {
            $this->error("Cannot read file: {$path}");

            return self::FAILURE;
        }

        $header = array_map(fn ($h) => Str::snake(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);

        foreach (['name', 'model_number', 'category'] as $required) {
            if (! in_array($required, $header, true)) {
                $this->error("Missing required column: {$required}");

                return self::FAILURE;
            }
        }

        $rows = [];
        $errors = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if ($cells === [null] || count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $row = array_map(fn ($v) => trim((string) $v), array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), '')));

            $problem = match (true) {
                $row['name'] === '' || mb_strlen($row['name']) > 200 => 'name is required (max 200)',
                $row['model_number'] === '' || mb_strlen($row['model_number']) > 64 => 'model_number is required (max 64)',
                $row['category'] === '' => 'category is required',
                ($row['price'] ?? '') !== '' && (! is_numeric($row['price']) || $row['price'] < 0) => 'price must be a non-negative number',
                ($row['price'] ?? '') !== '' && ! preg_match('/^[A-Za-z]{3}$/', $row['currency'] ?? '') => 'currency (3 letters) is required with a price',
                default => null,
            };

            $problem ? $errors[] = "Line {$line}: {$problem}" : $rows[] = $row;
        }
        fclose($handle);

        if ($errors) {
            foreach ($errors as $error) {
                $this->error($error);
            }
            $this->error('Nothing imported: fix the rows above.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info(count($rows).' rows are valid (dry run, nothing written).');

            return self::SUCCESS;
        }

        $created = $updated = 0;

        DB::transaction(function () use ($rows, &$created, &$updated) {
            foreach ($rows as $row) {
                $category = $this->category($row['category'], $row['subcategory'] ?? '');
                $brand = ($row['brand'] ?? '') !== ''
                    ? Brand::firstOrCreate(['slug' => Str::slug($row['brand'])], ['name' => $row['brand']])
                    : null;

                $product = Product::withTrashed()->firstOrNew(['sku' => $row['model_number']]);
                $isNew = ! $product->exists;

                $product->fill([
                    'category_id' => $category->id,
                    'brand_id' => $brand?->id,
                    'name' => $row['name'],
                    'short_description' => ($row['short_description'] ?? '') ?: null,
                    'description' => ($row['description'] ?? '') ?: null,
                    'price' => ($row['price'] ?? '') !== '' ? $row['price'] : null,
                    'currency_code' => ($row['price'] ?? '') !== '' ? strtoupper($row['currency']) : null,
                    'is_featured' => filter_var($row['featured'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ]);

                if ($isNew) {
                    $product->slug = $this->uniqueSlug(($row['slug'] ?? '') ?: $row['name']);
                    $product->status = Product::STATUS_DRAFT;
                }
                if ($this->option('publish')) {
                    $product->publish();
                }
                if ($product->trashed()) {
                    $product->restore();
                }

                $product->save();
                $isNew ? $created++ : $updated++;
            }
        });

        $this->info("Imported: {$created} created, {$updated} updated".($this->option('publish') ? ' (published).' : ' (as drafts; use --publish to go live).'));

        return self::SUCCESS;
    }

    private function category(string $name, string $sub): Category
    {
        $parent = Category::withTrashed()->firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);

        if ($sub === '') {
            return $parent;
        }

        return Category::withTrashed()->firstOrCreate(
            ['slug' => Str::slug("{$name} {$sub}")],
            ['name' => $sub, 'parent_id' => $parent->id]
        );
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: 'product';
        $slug = $base;

        for ($i = 2; Product::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
