<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('eid', 32)->unique();
            $t->string('eum_manufacturer')->nullable();
            $t->string('default_smdp_address')->nullable();
            $t->text('description')->nullable();
            $t->boolean('enabled')->default(true);
            $t->timestamps();

            $t->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
