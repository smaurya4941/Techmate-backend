<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: lets one typed pages table serve every managed content area
 * (general pages, policies, FAQs, industry solutions, services) instead of a
 * single untyped JSON blob. `section` drives the public API filter and ordering.
 */
return new class extends Migration
{
    public const SECTIONS = ['page', 'policy', 'faq', 'solution', 'service'];

    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->string('section', 20)->default('page')->after('slug');
            $table->unsignedInteger('sort_order')->default(0)->after('section');
            $table->index(['section', 'status', 'sort_order']);
        });

        SchemaChecks::in('pages', 'section', self::SECTIONS);
    }

    public function down(): void
    {
        if (SchemaChecks::supported()) {
            DB::statement('ALTER TABLE `pages` DROP CONSTRAINT `pages_section_valid`');
        }

        Schema::table('pages', function (Blueprint $table) {
            $table->dropIndex(['section', 'status', 'sort_order']);
            $table->dropColumn(['section', 'sort_order']);
        });
    }
};
