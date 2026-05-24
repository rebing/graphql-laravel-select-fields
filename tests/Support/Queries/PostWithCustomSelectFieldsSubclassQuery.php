<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Support\Queries;

use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;
use Rebing\GraphQL\Tests\Support\Models\Post;
use Rebing\GraphQL\Tests\Support\Objects\CustomSelectFields;

class PostWithCustomSelectFieldsSubclassQuery extends Query
{
    public static ?CustomSelectFields $captured = null;

    protected $attributes = [
        'name' => 'postWithCustomSelectFieldsSubclass',
    ];

    public function type(): Type
    {
        return GraphQL::type('Post');
    }

    public function args(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::id()),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $args
     */
    public function resolve(mixed $root, array $args, mixed $ctx, ResolveInfo $info, CustomSelectFields $fields): mixed
    {
        self::$captured = $fields;

        return Post::select($fields->getSelect())->findOrFail($args['id']);
    }
}
