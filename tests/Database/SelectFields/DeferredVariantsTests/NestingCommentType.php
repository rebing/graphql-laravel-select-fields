<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use GraphQL\Type\Definition\Type;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;
use Rebing\GraphQL\Tests\Support\Models\Comment;

/**
 * Middle of the nesting fixture graph (Task 7): `Post::comments()` (HasMany)
 * is the field that carries the OUTER variant conflict in the nesting tests;
 * `likes` (MorphMany, `userId` arg) is the plain nested relation for bullet
 * (1a), and — aliased twice with different `userId` values inside ONE
 * `comments` variant — the deferred-inside-deferred conflict for bullet (1b).
 */
class NestingCommentType extends GraphQLType
{
    protected $attributes = [
        'name' => 'NestingComment',
        'model' => Comment::class,
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::ID()),
            ],
            'likes' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(GraphQL::type('NestingLike')))),
                'args' => [
                    'userId' => [
                        'type' => Type::int(),
                    ],
                ],
                'query' => function (array $args, MorphMany $query): MorphMany {
                    if (isset($args['userId'])) {
                        $query->where(DB::raw('likes.user_id'), '=', $args['userId']);
                    }

                    return $query;
                },
            ],
        ];
    }
}
