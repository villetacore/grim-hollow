<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['name'=>'Grim Hollow','version'=>trim(file_get_contents(base_path('../../VERSION'))),'status'=>'local-alpha','api'=>'/api/v1']);
});
Route::view('/admin','moderation');
