<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_samples', function (Blueprint $table) {
            $table->id();
            $table->timestamp('recorded_at')->index();
            $table->decimal('cpu_usage', 5, 2)->default(0);
            $table->decimal('memory_percent', 5, 2)->default(0);
            $table->decimal('swap_percent', 5, 2)->default(0);
            $table->decimal('disk_percent', 5, 2)->default(0);
            $table->decimal('load_1', 8, 2)->default(0);
            $table->decimal('load_5', 8, 2)->default(0);
            $table->decimal('load_15', 8, 2)->default(0);
            $table->double('network_rx_rate')->default(0);
            $table->double('network_tx_rate')->default(0);
            $table->unsignedInteger('processes')->default(0);
            $table->decimal('temperature_celsius', 5, 1)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_samples');
    }
};
