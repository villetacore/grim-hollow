<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at');
        });
        Schema::create('characters', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignId('user_id')->constrained();
            $t->string('name', 32)->unique();
            $t->string('class_id')->default('guardian');
            $t->unsignedBigInteger('gold')->default(0);
            $t->unsignedBigInteger('xp')->default(0);
            $t->ulid('active_expedition')->nullable()->index();
            $t->timestamps();
        });
        Schema::create('expeditions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('host_id')->constrained('characters');
            $t->string('join_code', 12)->unique();
            $t->string('status')->index();
            $t->json('state');
            $t->unsignedBigInteger('revision')->default(0);
            $t->unsignedBigInteger('next_tick_at')->default(0);
            $t->timestamps();
        });
        Schema::create('expedition_members', function (Blueprint $t) {
            $t->foreignUlid('expedition_id')->constrained();
            $t->foreignUlid('character_id')->constrained();
            $t->unsignedBigInteger('last_seen');
            $t->primary(['expedition_id', 'character_id']);
        });
        Schema::create('command_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('expedition_id')->constrained();
            $t->foreignUlid('character_id')->constrained();
            $t->string('command_id', 36);
            $t->string('payload_hash', 64);
            $t->json('result');
            $t->unique(['expedition_id', 'character_id', 'command_id'], 'command_once');
        });
        Schema::create('settlements', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('expedition_id')->constrained();
            $t->foreignUlid('character_id')->constrained();
            $t->string('outcome');
            $t->unsignedBigInteger('gold');
            $t->unsignedBigInteger('xp');
            $t->timestamps();
            $t->unique(['expedition_id', 'character_id'], 'settle_once');
        });
        Schema::create('world_tickets', function (Blueprint $t) {
            $t->string('hash', 64)->primary();
            $t->foreignUlid('character_id')->constrained();
            $t->foreignUlid('expedition_id')->constrained();
            $t->timestamp('expires_at');
        });
        Schema::create('game_events', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('expedition_id')->constrained();
            $t->unsignedBigInteger('revision');
            $t->string('kind');
            $t->json('payload');
            $t->string('state_hash', 64);
            $t->timestamp('created_at');
            $t->unique(['expedition_id', 'revision']);
        });
    }

    public function down(): void
    {
        foreach (['game_events', 'world_tickets', 'settlements', 'command_receipts', 'expedition_members', 'expeditions', 'characters', 'game_sessions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
