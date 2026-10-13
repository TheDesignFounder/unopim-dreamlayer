<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dreamlayer_workflows', function (Blueprint $t) {
            $t->string('execution_path')->nullable();
            $t->uuid('upstream_execution_id')->nullable();
        });
        DB::table('dreamlayer_workflows')->whereNotNull('upstream_workflow_id')->update(['execution_path'=>'workflow_v1']);
    }

    public function down(): void
    {
        Schema::table('dreamlayer_workflows', fn (Blueprint $t) =>$t->dropColumn(['execution_path', 'upstream_execution_id']));
    }
};
