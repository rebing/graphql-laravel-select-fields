<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Rebing\GraphQL\Tests\Database\SelectFields\AliasedRelationArgTests\UsersQuery;

class CaptureUsersQuery extends UsersQuery
{
    /** @var Collection<int,\Rebing\GraphQL\Tests\Support\Models\User>|null */
    public static ?Collection $lastResult = null;

    public function resolve($root, array $args, $context, \GraphQL\Type\Definition\ResolveInfo $resolveInfo, Closure $getSelectFields)
    {
        return self::$lastResult = parent::resolve($root, $args, $context, $resolveInfo, $getSelectFields);
    }
}
