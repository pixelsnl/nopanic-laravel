<?php

namespace NoPanic\Laravel\Tests\Support;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;

class FakeUser extends Model implements AuthenticatableContract
{
    use Authenticatable;

    protected $guarded = [];
}
