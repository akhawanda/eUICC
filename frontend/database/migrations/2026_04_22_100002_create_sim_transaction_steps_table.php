<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sim_transaction_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sim_transaction_id')->constrained('sim_transactions')->cascadeOnDelete();
            $table->unsignedInteger('order')->default(0);
            $table->string('direction');         // request | response
            $table->string('phase');             // push_device | register_ipa | run_op
            $table->string('actor_from');        // dashboard | euicc | ipa
            $table->string('actor_to');
            $table->string('method')->nullable();
            $table->text('endpoint')->nullable();
            $table->unsignedInteger('http_status')->nullable();
            $table->json('http_headers')->nullable();
            $table->longText('http_body')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->timestamps();

            $table->index(['sim_transaction_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sim_transaction_steps');
    }
};
