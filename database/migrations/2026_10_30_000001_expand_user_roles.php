<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: replaces the Phase 3 placeholder roles (admin/sales/editor) with the
 * four approved staff roles. Existing rows are mapped, never deleted.
 */
return new class extends Migration
{
    private const MAP = [
        'admin' => 'super_admin',
        'editor' => 'catalogue_manager',
        'sales' => 'sales_executive',
    ];

    public function up(): void
    {
        $this->dropRoleCheck();

        foreach (self::MAP as $old => $new) {
            DB::table('users')->where('role', $old)->update(['role' => $new]);
        }

        Schema::table('users', function (Blueprint $table) {
            // Least-privilege default; higher roles must be granted explicitly.
            $table->string('role', 30)->default('sales_executive')->change();
        });

        SchemaChecks::in('users', 'role', ['super_admin', 'catalogue_manager', 'sales_executive', 'sales_manager']);
    }

    public function down(): void
    {
        $this->dropRoleCheck();

        // sales_manager has no Phase 3 equivalent; it falls back to the sales role.
        foreach (array_reverse(self::MAP, true) as $old => $new) {
            DB::table('users')->where('role', $new)->update(['role' => $old]);
        }
        DB::table('users')->where('role', 'sales_manager')->update(['role' => 'sales']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('sales')->change();
        });

        SchemaChecks::in('users', 'role', ['admin', 'sales', 'editor']);
    }

    private function dropRoleCheck(): void
    {
        if (SchemaChecks::supported()) {
            DB::statement('ALTER TABLE `users` DROP CONSTRAINT `users_role_valid`');
        }
    }
};
