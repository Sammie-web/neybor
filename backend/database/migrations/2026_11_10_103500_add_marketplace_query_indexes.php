<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->index(
                ['is_available', 'latitude', 'longitude'],
                'provider_profiles_location_availability_idx',
            );
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->index(['provider_id', 'status'], 'bookings_provider_status_idx');
            $table->index(['status', 'matching_started_at'], 'bookings_matching_status_started_idx');
        });

        Schema::table('provider_offers', function (Blueprint $table): void {
            $table->index(
                ['booking_id', 'matching_attempt', 'status', 'expires_at'],
                'provider_offers_matching_queue_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('provider_offers', function (Blueprint $table): void {
            $table->dropIndex('provider_offers_matching_queue_idx');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex('bookings_provider_status_idx');
            $table->dropIndex('bookings_matching_status_started_idx');
        });

        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->dropIndex('provider_profiles_location_availability_idx');
        });
    }
};
