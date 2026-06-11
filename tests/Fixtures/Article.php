<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Vitis\RestDB\Eloquent\InteractsWithRestApi;

class Article extends Model
{
    use InteractsWithRestApi;

    protected $connection = 'testapi';

    protected $table = 'articles';

    protected $guarded = [];

    public $timestamps = false;
}
