<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value');
            $table->timestamps();
        });

        Schema::create('waitlist_registrations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('email', 255);
            $table->string('role', 20);
            $table->string('phone', 32)->nullable();
            $table->string('business_name', 160)->nullable();
            $table->string('service_interest', 120)->nullable();
            $table->string('source', 40)->default('prelaunch_website');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['email', 'role']);
            $table->index(['role', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_registrations');
        Schema::dropIfExists('platform_settings');
    }
};
