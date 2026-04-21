<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_eim_associations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained()->cascadeOnDelete();
            $t->string('eim_id');
            $t->string('eim_fqdn');
            $t->unsignedInteger('counter_value')->default(0);
            $t->unsignedTinyInteger('supported_protocol')->default(0);
            $t->timestamps();

            $t->unique(['device_id', 'eim_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_eim_associations');
    }
};
