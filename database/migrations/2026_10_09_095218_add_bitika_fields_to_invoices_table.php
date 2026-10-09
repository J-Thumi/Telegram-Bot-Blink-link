<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('mpesa_phone_number', 20)->nullable();
            $table->string('payment_provider', 30)
                ->default('blink');

            $table->string('bitika_transaction_code')
                ->nullable()
                ->unique();

            $table->uuid('idempotency_key')->nullable()->unique();

            $table->json('payment_metadata')->nullable();

            $table->timestamp('payment_initiated_at')->nullable();

            $table->text('payment_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['bitika_transaction_code']);
            $table->dropUnique(['idempotency_key']);

            $table->dropColumn([
                'mpesa_phone_number',
                'payment_provider',
                'bitika_transaction_code',
                'idempotency_key',
                'payment_metadata',
                'payment_initiated_at',
                'payment_error',
            ]);
        });
    }
};