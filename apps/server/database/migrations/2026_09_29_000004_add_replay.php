<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {Schema::table('expeditions',function(Blueprint $t){$t->json('genesis')->nullable();$t->unsignedBigInteger('genesis_revision')->default(0);});}
    public function down():void {Schema::table('expeditions',fn(Blueprint $t)=>$t->dropColumn(['genesis','genesis_revision']));}
};
