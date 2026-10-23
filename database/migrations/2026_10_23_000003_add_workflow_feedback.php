<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dreamlayer_workflows', function (Blueprint $table) {
            $table->string('feedback', 20)->nullable();
            $table->timestamp('completed_at')->nullable();
        });
        \Illuminate\Support\Facades\DB::table('dreamlayer_workflows')->where('status', 'completed')->update(['completed_at' => \Illuminate\Support\Facades\DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('dreamlayer_workflows', fn (Blueprint $table) => $table->dropColumn(['feedback', 'completed_at']));
    }
};
