<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tackle_healing_log', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 20);    // job | scheduled_task
            $table->string('subject_class');
            $table->string('exception_class');
            $table->text('exception_message');
            $table->string('branch')->nullable();
            $table->string('pr_url', 500)->nullable();
            $table->string('mode', 10);            // pr | patch
            $table->boolean('tests_passed')->default(false);
            $table->string('outcome', 20);         // pr_opened | patched | failed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tackle_healing_log');
    }
};
