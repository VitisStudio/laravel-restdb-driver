<?php

declare(strict_types=1);

namespace Tests\Fixtures\JsonApi;

use Illuminate\Database\Eloquent\Model;
use Vitis\RestDB\JsonApi\IsJsonApiResource;

class Person extends Model
{
    use IsJsonApiResource;

    protected $connection = 'jsonapi';

    protected $table = 'people';

    protected $guarded = [];

    public $timestamps = false;
}
