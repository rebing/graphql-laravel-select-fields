<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;
use Rebing\GraphQL\Support\SelectFields;
use Rebing\GraphQL\Tests\Support\Models\User;

/**
 * Root query for the depth>=2 batching test: returns ALL users; `posts` is
 * a plain (no-conflict) relation so the variant conflict lives two levels
 * below this resolver, on `posts.comments`.
 */
class NestingUsersQuery extends Query
{
    protected $attributes = [
        'name' => 'nestingUsers',
    ];

    public function type(): Type
    {
        return Type::nonNull(Type::listOf(Type::nonNull(GraphQL::type('NestingUser'))));
    }

    /**
     * @param mixed $root
     * @param array<string,mixed> $args
     * @param mixed $context
     *
     * @return \Illuminate\Database\Eloquent\Collection<int,User>
     */
    public function resolve($root, array $args, $context, ResolveInfo $resolveInfo, Closure $getSelectFields)
    {
        /** @var SelectFields $selectFields */
        $selectFields = $getSelectFields();

        return User::query()
            ->select($selectFields->getSelect())
            ->with($selectFields->getRelations())
            ->orderBy('users.id')
            ->get();
    }
}
