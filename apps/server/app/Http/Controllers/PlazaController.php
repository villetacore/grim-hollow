<?php
namespace App\Http\Controllers;
use App\Game\Square;
use Illuminate\Http\Request;
final class PlazaController {
    public function show(Request $r,Square $square,string $id){
        return $square->view($r->attributes->get('account_id'),$id,$r->boolean('full'));
    }
    public function act(Request $r,Square $square,string $id){
        $d=$r->validate(['action'=>'required|in:move,talk,emote','direction'=>'required_if:action,move|in:north,south,east,west','target'=>'sometimes|string|max:24']);
        return $square->act($r->attributes->get('account_id'),$id,$d);
    }
}
