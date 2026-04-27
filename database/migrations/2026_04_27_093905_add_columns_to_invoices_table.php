<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            //
                $table->string('full_name')->nullable()->after('status');
                $table->string('username')->nullable()->after('full_name');
                $table->string('telegram_client_ip')->nullable()->after('username');
                $table->string('blink_client_ip')->nullable()->after('amount_msat');
                $table->string('satoshis_paid')->nullable()->after('blink_client_ip');
                $table->timestamp('paid_at')->nullable()->after('satoshis_paid');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            //
        });
    }
};
