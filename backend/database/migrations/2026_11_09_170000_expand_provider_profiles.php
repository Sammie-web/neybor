<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->string('business_name')->nullable();
            $table->json('skills')->nullable();
            $table->string('identity_document_type', 40)->nullable();
            $table->string('identity_document_path')->nullable();
            $table->timestamp('identity_submitted_at')->nullable();
        });

        Schema::create('provider_portfolio_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('users')->cascadeOnDelete();
            $table->string('image_path');
            $table->string('caption', 160)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['provider_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_portfolio_items');

        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'business_name',
                'skills',
                'identity_document_type',
                'identity_document_path',
                'identity_submitted_at',
            ]);
        });
    }
};
