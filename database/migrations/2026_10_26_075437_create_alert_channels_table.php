<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('type', 20);
            $table->text('destination');
            $table->boolean('enabled')->default(true);
            $table->boolean('notify_warning')->default(true);
            $table->boolean('notify_critical')->default(true);
            $table->boolean('notify_recovery')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_channels');
    }
};
