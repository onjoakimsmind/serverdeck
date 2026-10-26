<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel_name', 80);
            $table->string('event', 20);
            $table->string('check_key', 80);
            $table->string('check_title', 120);
            $table->string('status', 20);
            $table->text('message');
            $table->boolean('delivered')->default(false);
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['created_at', 'delivered']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_events');
    }
};
