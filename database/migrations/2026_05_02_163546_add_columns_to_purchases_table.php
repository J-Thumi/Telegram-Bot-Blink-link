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
        Schema::table('purchases', function (Blueprint $table) {
            //
            $table->string('subject')->nullable()->after('invoice_id');
            $table->datetime('image_sent_at')->nullable()->after('telegram_invite_link_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            //
            $table->dropColumn('subject');
            $table->dropColumn('image_sent_at');
        });
    }
};
