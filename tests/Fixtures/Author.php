<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Vitis\RestDB\Eloquent\InteractsWithRestApi;

class Author extends Model
{
    use InteractsWithRestApi;

    protected $connection = 'testapi';

    protected $table = 'authors';

    protected $guarded = [];

    public $timestamps = false;
}
