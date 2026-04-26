<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // For MySQL/MariaDB, we need to drop and recreate the column
        Schema::table('invoices', function (Blueprint $table) {
            // Drop the column first
            $table->dropColumn('payment_request');
        });
        
        Schema::table('invoices', function (Blueprint $table) {
            // Re-add as TEXT
            $table->text('payment_request')->after('payment_hash');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('payment_request');
            $table->string('payment_request', 255)->after('payment_hash');
        });
    }
};