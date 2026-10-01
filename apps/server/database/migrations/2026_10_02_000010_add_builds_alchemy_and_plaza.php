<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('characters',function(Blueprint $t){
            $t->string('origin',16)->default('human');
            $t->string('mentor',16)->nullable();
            $t->text('reagents')->nullable();
            $t->string('elixir',40)->nullable();
        });
        // Generated keys now carry a material and a rune: "greatsword+120@shadowsteel~vampiric!thurisaz".
        Schema::table('character_items',function(Blueprint $t){$t->string('definition',64)->change();});
        Schema::create('plaza_presence',function(Blueprint $t){
            $t->foreignUlid('character_id')->primary()->constrained('characters')->cascadeOnDelete();
            $t->unsignedSmallInteger('x');$t->unsignedSmallInteger('y');$t->string('facing',8)->default('south');
            $t->unsignedBigInteger('moved_at')->default(0);$t->unsignedBigInteger('seen_at')->default(0)->index();
            $t->string('emote',16)->nullable();$t->unsignedBigInteger('emote_until')->default(0);
        });
    }
    public function down(): void {
        Schema::dropIfExists('plaza_presence');
        Schema::table('character_items',function(Blueprint $t){$t->string('definition',32)->change();});
        Schema::table('characters',function(Blueprint $t){$t->dropColumn(['origin','mentor','reagents','elixir']);});
    }
};
