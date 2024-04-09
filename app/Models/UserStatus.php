<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class UserStatus extends Model
{
    use HasFactory;
    protected $table = "user_status";

    protected $fillable = [
        "id", "title", "status"
    ];

    public function user(){
        return $this->belongsTo('App\Models\User', 'user_status_id','id');
    }
}
