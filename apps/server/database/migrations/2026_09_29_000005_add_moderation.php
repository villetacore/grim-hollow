<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::table('users',function(Blueprint $t){$t->boolean('game_admin')->default(false);$t->timestamp('muted_until')->nullable();});
        Schema::create('moderation_actions',function(Blueprint $t){$t->id();$t->foreignId('admin_id')->constrained('users');$t->foreignId('report_id')->constrained('chat_reports');$t->string('action',16);$t->string('reason',280);$t->timestamp('created_at');});
    }
    public function down():void {Schema::dropIfExists('moderation_actions');Schema::table('users',fn(Blueprint $t)=>$t->dropColumn(['game_admin','muted_until']));}
};
