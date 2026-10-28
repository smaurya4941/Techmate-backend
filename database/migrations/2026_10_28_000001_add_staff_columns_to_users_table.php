<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email');
            // Least-privilege default; 'admin' must be granted explicitly.
            $table->string('role', 20)->default('sales')->after('password');
            $table->boolean('is_active')->default(true)->after('role');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
        });

        SchemaChecks::in('users', 'role', ['admin', 'sales', 'editor']);
    }

    public function down(): void
    {
        if (SchemaChecks::supported()) {
            DB::statement('ALTER TABLE `users` DROP CONSTRAINT `users_role_valid`');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'role', 'is_active', 'last_login_at']);
        });
    }
};
