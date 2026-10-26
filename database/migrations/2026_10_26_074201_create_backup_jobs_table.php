<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('backup_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('kind', 16);
            $table->string('target', 255);
            $table->string('frequency', 16)->default('daily');
            $table->unsignedTinyInteger('hour')->default(3);
            $table->unsignedTinyInteger('minute')->default(0);
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->boolean('recursive')->default(false);
            $table->unsignedSmallInteger('retention')->default(7);
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 16)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamps();

            $table->index(['kind', 'target']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_jobs');
    }
};
