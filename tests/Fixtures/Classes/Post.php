<?php

namespace Nitro\Tests\Fixtures\Classes;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}
