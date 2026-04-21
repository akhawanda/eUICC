<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ipa_sessions', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('operation');           // retrieve_eim_package | profile_download | add_eim | esep_execute | ...
            $t->json('device_ids');            // [1,2,3] — targets (multi-device fan-out)
            $t->json('parameters')->nullable();
            $t->json('results')->nullable();   // { "<eid>": { status, response, ms } }
            $t->string('status')->default('pending'); // pending | running | completed | failed
            $t->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();

            $t->index(['operation', 'status']);
            $t->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ipa_sessions');
    }
};
