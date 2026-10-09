
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('blink_id')->nullable()->change();
            $table->string('payment_hash')->nullable()->change();
            $table->string('payment_request')->nullable()->change();
            $table->bigInteger('amount_msat')->nullable()->change();

            // The Bitika payment amount is in Kenyan shillings.
            $table->unsignedBigInteger('amount_kes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('amount_kes');

            // Existing rows must have non-null values before
            // restoring NOT NULL constraints.
            $table->string('blink_id')->nullable(false)->change();
            $table->string('payment_hash')->nullable(false)->change();
            $table->string('payment_request')->nullable(false)->change();
            $table->bigInteger('amount_msat')->nullable(false)->change();
        });
    }
};
