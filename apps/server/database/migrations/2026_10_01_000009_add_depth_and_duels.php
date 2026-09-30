<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('characters',function(Blueprint $t){
            $t->unsignedInteger('best_depth')->default(0);
            $t->unsignedInteger('rating')->default(1000);
            $t->unsignedInteger('duel_wins')->default(0);
            $t->unsignedInteger('duel_losses')->default(0);
        });
    }
    public function down(): void {
        Schema::table('characters',function(Blueprint $t){$t->dropColumn(['best_depth','rating','duel_wins','duel_losses']);});
    }
};
