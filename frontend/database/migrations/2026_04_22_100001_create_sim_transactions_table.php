<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sim_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ipa_session_id')->nullable()->constrained('ipa_sessions')->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('eid')->index();
            $table->string('operation')->index();
            $table->string('status')->default('pending')->index();
            $table->string('result_summary')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sim_transactions');
    }
};
