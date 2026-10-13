<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dreamlayer_workflows', function (Blueprint $t) {
            $t->uuid('upstream_workflow_id')->nullable();
            $t->uuid('input_asset_id')->nullable();
            $t->json('progress')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dreamlayer_workflows', fn (Blueprint $t) =>$t->dropColumn(['upstream_workflow_id', 'input_asset_id', 'progress']));
    }
};
