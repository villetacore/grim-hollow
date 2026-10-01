<?php

namespace App\Http\Controllers;

use App\Game\World;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class GameController
{
    public function register(Request $r)
    {
        $r->merge(['email' => mb_strtolower(trim((string) $r->input('email')))]);
        $d = $r->validate(['email' => 'required|email|max:254|unique:users,email', 'password' => 'required|string|min:12|max:128']);
        $id = DB::table('users')->insertGetId(['name' => 'Player', 'email' => $d['email'], 'password' => Hash::make($d['password']), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json($this->session($id), 201);
    }

    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|string|max:254', 'password' => 'required|string|max:128']);
        $u = DB::table('users')->where('email', mb_strtolower(trim($d['email'])))->first();
        abort_unless($u && Hash::check($d['password'], $u->password), 401, 'invalid_credentials');

        return $this->session($u->id);
    }

    private function session(int $id): array
    {
        $token = bin2hex(random_bytes(32));
        DB::table('game_sessions')->insert(['user_id' => $id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(8)]);

        return ['access_token' => $token, 'expires_in' => 28800];
    }

    public function logout(Request $r)
    {
        DB::table('game_sessions')->where('token_hash', hash('sha256', $r->bearerToken()))->delete();

        return response()->noContent();
    }

    public function refresh(Request $r)
    {
        return DB::transaction(function()use($r){
            $hash=hash('sha256',$r->bearerToken());$old=DB::table('game_sessions')->where('token_hash',$hash)->lockForUpdate()->first();
            abort_unless($old && $old->expires_at>now()->toDateTimeString(),401,'session_expired');
            DB::table('game_sessions')->where('token_hash',$hash)->delete();return $this->session($old->user_id);
        },3);
    }

    public function characters(Request $r)
    {
        return ['items' => DB::table('characters')->where('user_id', $r->attributes->get('account_id'))->get(['id', 'name', 'class_id', 'origin', 'gold', 'xp', 'active_expedition'])];
    }

    public function createCharacter(Request $r)
    {
        $d = $r->validate(['name' => ['required', 'string', 'min:3', 'max:24', 'regex:/^[\pL][\pL\pN_]+$/u', 'unique:characters,name'],
            'class_id'=>'sometimes|in:'.implode(',',array_keys(\GrimHollow\Core\Catalog::classes())),
            'origin'=>'sometimes|in:'.implode(',',array_keys(\GrimHollow\Core\Catalog::origins()))]);
        $id = (string) Str::ulid();
        DB::transaction(function() use($id,$r,$d) {
            $account=$r->attributes->get('account_id');
            DB::table('users')->where('id',$account)->lockForUpdate()->first();
            abort_if(DB::table('characters')->where('user_id',$account)->count()>=8,409,'character_limit');
            DB::table('characters')->insert(['id' => $id, 'user_id' => $account, 'name' => $d['name'], 'class_id'=>$d['class_id']??'guardian',
                'origin'=>$d['origin']??'human', 'created_at' => now(), 'updated_at' => now()]);
            (new \App\Game\Characters)->starter(DB::table('characters')->where('id',$id)->first());
        },3);

        return response()->json(['id' => $id, 'name' => $d['name']], 201);
    }

    public function expedition(Request $r, World $world)
    {
        $d = $r->validate(['character_id' => 'required|ulid', 'join_code' => 'nullable|string|size:10','biome'=>'sometimes|in:'.implode(',',array_keys(\GrimHollow\Core\Catalog::biomes())),'mode'=>'sometimes|in:expedition,duel']);
        $c = $world->character($r->attributes->get('account_id'), $d['character_id']);
        $e = empty($d['join_code']) ? $world->create($c,$d['biome']??'mines',$d['mode']??'expedition') : $world->join($c, $d['join_code']);

        return ['id' => $e->id, 'join_code' => $e->join_code, 'status' => $e->status];
    }

    public function start(Request $r, World $world, string $id)
    {
        $d = $r->validate(['character_id' => 'required|ulid']);
        $c = $world->character($r->attributes->get('account_id'), $d['character_id']);
        $world->start($id, $c->id);

        return ['status' => 'active'];
    }

    public function snapshot(Request $r, World $world, string $id)
    {
        $d = $r->validate(['character_id' => 'required|ulid']);
        $c = $world->character($r->attributes->get('account_id'), $d['character_id']);

        return $world->snapshot($id, $c->id);
    }

    public function leave(Request $r, World $world, string $id)
    {
        $d=$r->validate(['character_id'=>'required|ulid']);
        $c=$world->character($r->attributes->get('account_id'),$d['character_id']);
        $world->leave($id,$c->id);
        return ['status'=>'left'];
    }

    public function command(Request $r, World $world, string $id)
    {
        $d = $r->validate(['character_id' => 'required|ulid', 'command_id' => 'required|uuid', 'payload' => 'required|array',
            'payload.action' => 'required|in:move,attack,bash,guard,potion,extract,descend,cast,revive,interact', 'payload.spell_id'=>'sometimes|in:'.implode(',',array_keys(\GrimHollow\Core\Catalog::spells())),
            'payload.direction' => 'sometimes|in:north,south,east,west', 'payload.target_id' => 'sometimes|string|max:26']);
        $c = $world->character($r->attributes->get('account_id'), $d['character_id']);

        return $world->command($id, $c->id, $d['command_id'], $d['payload']);
    }

    public function ticket(Request $r, World $world)
    {
        $d = $r->validate(['character_id' => 'required|ulid', 'expedition_id' => 'required|ulid']);
        $c = $world->character($r->attributes->get('account_id'), $d['character_id']);
        $world->snapshot($d['expedition_id'], $c->id);
        $token = bin2hex(random_bytes(32));
        DB::table('world_tickets')->insert(['hash' => hash('sha256', $token), 'character_id' => $c->id,
            'expedition_id' => $d['expedition_id'], 'session_hash'=>hash('sha256',$r->bearerToken()),'expires_at' => now()->addSeconds(30)]);

        return ['ticket' => $token, 'expires_in' => 30];
    }
}
