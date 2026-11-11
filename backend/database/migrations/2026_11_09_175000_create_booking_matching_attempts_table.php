<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_matching_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt');
            $table->string('status', 32)->index();
            $table->unsignedInteger('candidate_count')->default(0);
            $table->unsignedInteger('offers_dispatched')->default(0);
            $table->text('failure_reason')->nullable();
            $table->dateTime('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['booking_id', 'attempt']);
            $table->index(['booking_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_matching_attempts');
    }
};
