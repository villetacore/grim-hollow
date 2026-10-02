<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** A world ticket without an expedition opens the live town square. */
    public function up(): void {
        Schema::table('world_tickets',function(Blueprint $t){$t->ulid('expedition_id')->nullable()->change();});
        Schema::table('chat_messages',function(Blueprint $t){$t->index(['channel','created_at']);});
    }
    public function down(): void {
        Schema::table('chat_messages',function(Blueprint $t){$t->dropIndex(['channel','created_at']);});
        Schema::table('world_tickets',function(Blueprint $t){$t->ulid('expedition_id')->nullable(false)->change();});
    }
};
