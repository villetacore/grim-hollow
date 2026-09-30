<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('characters',function(Blueprint $t){
            $t->unsignedInteger('essence')->default(0);
            $t->text('bounty')->nullable();
        });
    }
    public function down(): void {
        Schema::table('characters',function(Blueprint $t){$t->dropColumn(['essence','bounty']);});
    }
};
