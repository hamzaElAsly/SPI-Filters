<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->foreignId('categorie_id')
                ->nullable()
                ->after('image')
                ->constrained('categories')
                ->nullOnDelete();
            $table->unsignedBigInteger('woocommerce_id')->nullable()->after('categorie_id')->index();
            $table->timestamp('woocommerce_synced_at')->nullable()->after('woocommerce_id');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropForeign(['categorie_id']);
            $table->dropColumn(['categorie_id', 'woocommerce_id', 'woocommerce_synced_at']);
        });
    }
};
