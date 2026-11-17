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
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('balance', 18, 9)->default(0)->after('password');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('held_amount', 18, 9)->default(0)->after('estimated_max_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('held_amount');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('balance');
        });
    }
};
