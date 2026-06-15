<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    public function decisionWheels()
    {
        return $this->hasMany(DecisionWheel::class);
    }

    public function blitzPolls()
    {
        return $this->hasMany(BlitzPoll::class);
    }

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'photo_path',
        'photo_url',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}