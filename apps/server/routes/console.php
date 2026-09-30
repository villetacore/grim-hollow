<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('game:verify-replay {expedition}',function(){
    try {$this->line(json_encode(app(\App\Game\Replay::class)->verify($this->argument('expedition')),JSON_PRETTY_PRINT));}
    catch(\Throwable $e){$this->error($e->getMessage());return 1;}
})->purpose('Verify persisted events against the authoritative snapshot without changing rewards');

Artisan::command('game:status',function(){
    $this->table(['Metric','Value'],[
        ['active_expeditions',\Illuminate\Support\Facades\DB::table('expeditions')->where('status','active')->count()],
        ['characters',\Illuminate\Support\Facades\DB::table('characters')->count()],
        ['open_market_listings',\Illuminate\Support\Facades\DB::table('market_listings')->where('status','open')->count()],
        ['settlements',\Illuminate\Support\Facades\DB::table('settlements')->count()],
        ['content_version',\GrimHollow\Core\Game::CONTENT_VERSION],
    ]);
})->purpose('Read local game service metrics');

Artisan::command('game:admin {email} {--revoke}',function(){
    $n=\Illuminate\Support\Facades\DB::table('users')->where('email',mb_strtolower($this->argument('email')))->update(['game_admin'=>!$this->option('revoke')]);
    if(!$n){$this->error('Account not found');return 1;}$this->info('Administrator access updated.');
})->purpose('Grant or revoke moderation access for an existing account from the server console');

Artisan::command('game:benchmark {--seconds=60} {--players=100}',function(){
    $seconds=(int)$this->option('seconds');$players=(int)$this->option('players');
    if($seconds<1||$seconds>3600||$players<4||$players>1000){$this->error('seconds: 1..3600; players: 4..1000');return 1;}
    $this->line(json_encode(\App\Game\Benchmark::run($seconds,$players),JSON_PRETTY_PRINT));
})->purpose('Measure core rules and visibility; excludes network and database load');
