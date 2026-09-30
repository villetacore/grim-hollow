<?php

use App\Http\Controllers\GameController;
use App\Http\Controllers\RpgController;
use App\Http\Controllers\TownController;
use App\Http\Controllers\ModerationController;
use App\Http\Middleware\GameSession;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [GameController::class, 'register'])->middleware('throttle:6,1,register:');
    Route::post('auth/login', [GameController::class, 'login'])->middleware('throttle:10,1,login:');
    Route::middleware([GameSession::class, 'throttle:1200,1,game:'])->group(function () {
        Route::post('auth/logout', [GameController::class, 'logout']);
        Route::post('auth/refresh', [GameController::class, 'refresh'])->middleware('throttle:10,1,refresh:');
        Route::get('characters', [GameController::class, 'characters']);
        Route::get('characters/{id}', [RpgController::class, 'sheet']);
        Route::get('characters/{id}/town', [TownController::class, 'show']);
        Route::post('characters/{id}/town', [TownController::class, 'act'])->middleware('throttle:60,1,town:');
        Route::post('characters/{id}/manage', [RpgController::class, 'manage']);
        Route::get('chat', [RpgController::class, 'chat']);
        Route::post('chat/reports', [ModerationController::class, 'report'])->middleware('throttle:10,1,reports:');
        Route::get('admin/reports', [ModerationController::class, 'index']);
        Route::post('admin/reports', [ModerationController::class, 'decide']);
        Route::post('chat', [RpgController::class, 'postChat'])->middleware('throttle:30,1,chat:');
        Route::post('characters', [GameController::class, 'createCharacter'])->middleware('throttle:10,1,characters:');
        Route::post('expeditions', [GameController::class, 'expedition'])->middleware('throttle:30,1,expeditions:');
        Route::post('expeditions/{id}/start', [GameController::class, 'start']);
        Route::post('expeditions/{id}/leave', [GameController::class, 'leave']);
        Route::get('expeditions/{id}', [GameController::class, 'snapshot']);
        Route::post('expeditions/{id}/commands', [GameController::class, 'command']);
        Route::post('world/tickets', [GameController::class, 'ticket'])->middleware('throttle:30,1,tickets:');
    });
});
