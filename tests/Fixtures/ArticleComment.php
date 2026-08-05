<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Vitis\RestDB\Eloquent\InteractsWithRestApi;

class ArticleComment extends Model
{
    use InteractsWithRestApi;

    protected $connection = 'testapi';

    protected $table = 'comments';

    protected $guarded = [];

    public $timestamps = false;
}
