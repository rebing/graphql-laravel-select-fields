<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;
use Rebing\GraphQL\Support\SelectFields;
use Rebing\GraphQL\Tests\Support\Models\Post;

/**
 * Root query for the nesting tests: returns ALL posts (no filter arg), so
 * two aliased occurrences at the QUERY root (`x: nestingPosts`, `y:
 * nestingPosts`) are two fully independent resolver invocations returning
 * the SAME underlying rows — the shape needed for the aliased-ancestors and
 * cross-root dedupe tests.
 */
class NestingPostsQuery extends Query
{
    protected $attributes = [
        'name' => 'nestingPosts',
    ];

    public function args(): array
    {
        return [
            // Optional root filter (late-wave re-batching test): lets one
            // root branch cover only a SUBSET of the posts another branch
            // reaches through a deferred posts variant, so the shared
            // comments loaders receive parents AFTER their first force.
            'flag' => [
                'type' => Type::boolean(),
            ],
        ];
    }

    public function type(): Type
    {
        return Type::nonNull(Type::listOf(Type::nonNull(GraphQL::type('NestingPost'))));
    }

    /**
     * @param mixed $root
     * @param array<string,mixed> $args
     * @param mixed $context
     *
     * @return \Illuminate\Database\Eloquent\Collection<int,Post>
     */
    public function resolve($root, array $args, $context, ResolveInfo $resolveInfo, Closure $getSelectFields)
    {
        /** @var SelectFields $selectFields */
        $selectFields = $getSelectFields();

        return Post::query()
            ->select($selectFields->getSelect())
            ->with($selectFields->getRelations())
            ->when(isset($args['flag']), static function ($query) use ($args): void {
                $query->where(\Illuminate\Support\Facades\DB::raw('posts.flag'), '=', $args['flag']);
            })
            ->orderBy('posts.id')
            ->get();
    }
}
