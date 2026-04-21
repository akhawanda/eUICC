<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_preloaded_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained()->cascadeOnDelete();
            $t->string('iccid', 20);
            $t->string('name');
            $t->string('sp_name')->nullable();
            $t->enum('state', ['enabled', 'disabled'])->default('disabled');
            $t->enum('class', ['test', 'provisioning', 'operational'])->default('operational');
            $t->timestamps();

            $t->unique(['device_id', 'iccid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_preloaded_profiles');
    }
};
