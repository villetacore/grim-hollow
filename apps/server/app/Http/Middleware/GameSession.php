<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class GameSession
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        abort_unless($token && strlen($token) <= 128, 401, 'unauthenticated');
        $session = DB::table('game_sessions')->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
        abort_unless($session, 401, 'session_expired');
        $request->attributes->set('account_id', $session->user_id);

        return $next($request);
    }
}
