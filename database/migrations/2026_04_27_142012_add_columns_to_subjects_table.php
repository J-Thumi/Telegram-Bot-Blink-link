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
        Schema::table('subjects', function (Blueprint $table) {
            //
            $table->integer('members_count')->default(0)->after('color');
            $table->string('status')->default('Not Sent')->after('members_count');
            $table->datetime('due_date')->nullable()->after('status');
            $table->datetime('images_sent_at')->nullable()->after('due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            //
        });
    }
};
