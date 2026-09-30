<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
    public function up(): void {
        Schema::table('characters',function(Blueprint $t){
            $t->unsignedInteger('materials')->default(0);$t->unsignedInteger('supplies')->default(0);
            $t->unsignedInteger('campaign')->default(0);$t->unsignedInteger('craft_xp')->default(0);
        });
        Schema::table('character_items',function(Blueprint $t){$t->boolean('bound')->default(false);$t->boolean('escrow')->default(false);});
        DB::table('character_items')->whereNotNull('equipped_slot')->update(['bound'=>true]);
        Schema::create('market_listings',function(Blueprint $t){
            $t->ulid('id')->primary();$t->foreignUlid('seller_id')->constrained('characters');
            $t->ulid('item_id');$t->unsignedBigInteger('price');$t->string('status',16)->default('open')->index();
            $t->ulid('buyer_id')->nullable();$t->timestamp('created_at');
        });
        Schema::create('economy_ledger',function(Blueprint $t){
            $t->id();$t->foreignUlid('character_id')->constrained('characters');$t->string('reason',40);
            $t->bigInteger('gold_delta');$t->bigInteger('materials_delta')->default(0);$t->string('reference',80);$t->timestamp('created_at');
        });
        Schema::create('guilds',function(Blueprint $t){$t->ulid('id')->primary();$t->string('name',32)->unique();$t->foreignUlid('leader_id')->constrained('characters');$t->timestamp('created_at');});
        Schema::create('guild_members',function(Blueprint $t){$t->foreignUlid('character_id')->primary()->constrained('characters');$t->foreignUlid('guild_id')->constrained('guilds')->cascadeOnDelete();$t->timestamp('created_at');});
        Schema::create('guild_invites',function(Blueprint $t){$t->foreignUlid('guild_id')->constrained('guilds')->cascadeOnDelete();$t->foreignUlid('character_id')->constrained('characters');$t->primary(['guild_id','character_id']);});
        Schema::create('friendships',function(Blueprint $t){$t->foreignId('sender_id')->constrained('users');$t->foreignId('receiver_id')->constrained('users');$t->boolean('accepted')->default(false);$t->primary(['sender_id','receiver_id']);});
        Schema::create('chat_reports',function(Blueprint $t){$t->id();$t->foreignId('reporter_id')->constrained('users');$t->foreignId('message_id')->constrained('chat_messages');$t->string('reason',280);$t->string('status',16)->default('open');$t->timestamp('created_at');$t->unique(['reporter_id','message_id']);});
    }
    public function down(): void {
        foreach(['chat_reports','friendships','guild_invites','guild_members','guilds','economy_ledger','market_listings'] as $table) Schema::dropIfExists($table);
        Schema::table('character_items',fn(Blueprint $t)=>$t->dropColumn(['bound','escrow']));
        Schema::table('characters',fn(Blueprint $t)=>$t->dropColumn(['materials','supplies','campaign','craft_xp']));
    }
};
