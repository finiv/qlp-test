<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReplyTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'client_id',
        'event_id',
        'sentiment',
        'body',
        'status',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
