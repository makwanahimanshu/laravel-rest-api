<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class UserType extends Model
{
    use HasFactory;

    protected $table = "user_type";

    protected $fillable = [
        "id", "title", "status"
    ];

    protected $hidden = ["created_by", "updated_by", "deleted_by", "created_at", "updated_at", "deleted_at"];

    public function user(){
        return $this->belongsTo('App\Models\User', 'user_type_id','id');
    }
}
