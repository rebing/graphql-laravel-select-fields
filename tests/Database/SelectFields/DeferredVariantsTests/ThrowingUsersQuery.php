<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use Closure;
use Rebing\GraphQL\Tests\Database\SelectFields\AliasedRelationArgTests\UsersQuery;
use RuntimeException;

/**
 * For the "exception unwind" test: calls `$getSelectFields()` first (so any
 * argument-variant specs on the query get registered, exactly like a normal
 * resolver), THEN throws — simulating a resolver failure AFTER the registry
 * has live, unconsumed specs in it.
 */
class ThrowingUsersQuery extends UsersQuery
{
    protected $attributes = [
        'name' => 'throwingUsers',
    ];

    /**
     * @param array<string,mixed> $args
     */
    public function resolve($root, array $args, $context, \GraphQL\Type\Definition\ResolveInfo $resolveInfo, Closure $getSelectFields): never
    {
        // Registers any variants exactly like the real #604 path.
        $getSelectFields();

        throw new RuntimeException('boom: resolver threw mid-execution');
    }
}
