<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('download_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed'])
                  ->default('pending');
            $table->string('filename')->nullable();
            $table->string('stored_path')->nullable();
            $table->unsignedBigInteger('total_size')->nullable();
            $table->unsignedBigInteger('received_size')->default(0);
            $table->unsignedInteger('total_chunks')->nullable();
            $table->unsignedInteger('received_chunks')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_requests');
    }
};
