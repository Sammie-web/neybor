<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->boolean('is_available')->default(false)->index();
            $table->decimal('coverage_radius_km', 6, 2)->default(15);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->unsignedInteger('matching_attempt')->default(1);
            $table->timestamp('matching_started_at')->nullable();
            $table->timestamp('matched_at')->nullable();
        });

        Schema::create('provider_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('matching_attempt');
            $table->string('status', 20)->default('pending')->index();
            $table->decimal('score', 10, 6);
            $table->decimal('distance_km', 8, 2);
            $table->dateTime('offered_at');
            $table->dateTime('expires_at')->index();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['booking_id', 'matching_attempt', 'provider_id']);
            $table->index(['provider_id', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_offers');

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn([
                'matching_attempt',
                'matching_started_at',
                'matched_at',
            ]);
        });

        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->dropColumn(['is_available', 'coverage_radius_km']);
        });
    }
};
