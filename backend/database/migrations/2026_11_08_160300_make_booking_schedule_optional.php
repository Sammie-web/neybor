<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestamp('scheduled_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('bookings')
            ->whereNull('scheduled_at')
            ->update(['scheduled_at' => now()]);

        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestamp('scheduled_at')->nullable(false)->change();
        });
    }
};
