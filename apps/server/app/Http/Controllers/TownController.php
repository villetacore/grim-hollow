<?php
namespace App\Http\Controllers;
use App\Game\Town;
use Illuminate\Http\Request;
final class TownController {
    public function show(Request $r,Town $town,string $id){return $town->overview($r->attributes->get('account_id'),$id);}
    public function act(Request $r,Town $town,string $id){
        $d=$r->validate(['operation_id'=>'required|uuid','action'=>'required|in:salvage,list,buy,cancel,craft,supply,respec,talent,guild_create,guild_invite,guild_accept,guild_leave,friend_request,friend_accept,friend_remove,distill,bounty_take,bounty_claim,bounty_drop',
            'target'=>'sometimes|string|max:40','text'=>'sometimes|string|max:32','price'=>'sometimes|integer|min:1|max:1000000']);
        return $town->act($r->attributes->get('account_id'),$id,$d);
    }
}
