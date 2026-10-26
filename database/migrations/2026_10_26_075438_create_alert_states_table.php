<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_states', function (Blueprint $table) {
            $table->id();
            $table->string('check_key', 80)->unique();
            $table->string('check_title', 120);
            $table->string('last_status', 20);
            $table->string('last_notified_status', 20)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index('last_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_states');
    }
};
