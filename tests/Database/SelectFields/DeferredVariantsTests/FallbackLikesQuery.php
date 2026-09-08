<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;
use Rebing\GraphQL\Support\SelectFields;
use Rebing\GraphQL\Tests\Support\Models\Like;

class FallbackLikesQuery extends Query
{
    protected $attributes = [
        'name' => 'fallbackLikes',
    ];

    public function type(): Type
    {
        return Type::nonNull(Type::listOf(Type::nonNull(GraphQL::type('FallbackLike'))));
    }

    /**
     * @param mixed $root
     * @param array<string,mixed> $args
     * @param mixed $context
     *
     * @return \Illuminate\Database\Eloquent\Collection<int,Like>
     */
    public function resolve($root, array $args, $context, ResolveInfo $resolveInfo, Closure $getSelectFields)
    {
        /** @var SelectFields $selectFields */
        $selectFields = $getSelectFields();

        return Like::query()
            ->select($selectFields->getSelect())
            ->with($selectFields->getRelations())
            ->orderBy('likes.id')
            ->get();
    }
}
