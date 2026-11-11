<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->boolean('provider_was_available')->default(false);
        });

        Schema::create('booking_status_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['booking_id', 'created_at']);
        });

        Schema::create('booking_provider_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['booking_id', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_provider_exclusions');
        Schema::dropIfExists('booking_status_events');

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('provider_was_available');
        });
    }
};
