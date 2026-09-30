<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('characters', function(Blueprint $t) {
            $t->unsignedInteger('strength')->default(0); $t->unsignedInteger('vitality')->default(0);
            $t->unsignedInteger('intellect')->default(0); $t->boolean('starter_granted')->default(false);
        });
        Schema::create('character_items', function(Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('character_id')->constrained('characters')->cascadeOnDelete();
            $t->string('definition',32); $t->string('equipped_slot',16)->nullable();
            $t->unique(['character_id','equipped_slot']); $t->timestamp('created_at');
        });
        Schema::create('character_operations', function(Blueprint $t) {
            $t->id(); $t->foreignUlid('character_id')->constrained('characters')->cascadeOnDelete();
            $t->uuid('operation_id'); $t->string('payload_hash',64); $t->unique(['character_id','operation_id']);
        });
        Schema::create('chat_messages', function(Blueprint $t) {
            $t->id(); $t->foreignUlid('character_id')->constrained('characters')->cascadeOnDelete();
            $t->string('channel',40)->index(); $t->uuid('message_id'); $t->string('body',280); $t->timestamp('created_at');
            $t->unique(['character_id','message_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('chat_messages'); Schema::dropIfExists('character_operations'); Schema::dropIfExists('character_items');
        Schema::table('characters',fn(Blueprint $t)=>$t->dropColumn(['strength','vitality','intellect','starter_granted']));
    }
};
