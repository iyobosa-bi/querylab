<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Area extends Model
{
    protected $fillable = ['name', 'state'];

    public function technicians(): HasMany
    {
        return $this->hasMany(Technician::class);
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
