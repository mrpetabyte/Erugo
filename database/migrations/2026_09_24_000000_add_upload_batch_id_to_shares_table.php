<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Stores "{user_id}:{upload_id}" of the upload batch a share was created
     * from, so a retried create-share-from-uploads request returns the
     * existing share instead of failing.
     */
    public function up(): void
    {
        Schema::table('shares', function (Blueprint $table) {
            $table->string('upload_batch_id', 255)->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shares', function (Blueprint $table) {
            $table->dropUnique(['upload_batch_id']);
            $table->dropColumn('upload_batch_id');
        });
    }
};
