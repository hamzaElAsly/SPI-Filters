<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('produits', 'old_prix')) {
            Schema::table('produits', function (Blueprint $table): void {
                $table->decimal('old_prix', 12, 2)->nullable()->after('prix_vente');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('produits', 'old_prix')) {
            Schema::table('produits', function (Blueprint $table): void {
                $table->dropColumn('old_prix');
            });
        }
    }
};
