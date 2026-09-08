<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\NullableType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type as GraphQLType;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Rebing\GraphQL\Support\Contracts\WrapType;
use Rebing\GraphQL\Support\Facades\GraphQL;

/**
 * Minimal WrapType-marked type for the Task 7 "unsupported: paginated/wrapped
 * relation field" fallback test.
 *
 * This deliberately does NOT reuse `WrapTypeTests\CustomWrapperType` (or the
 * package's own `Rebing\GraphQL\Support\SelectFields\PaginationType` via
 * `GraphQL::paginate()`): both of those type their 'data' resolver against a
 * concrete `LengthAwarePaginator`, which is only ever produced by a ROOT
 * query resolver calling ->paginate() itself. A NESTED relation field
 * resolved through legacy eager loading (the §2.1a fallback path this test
 * exercises) can only ever populate a plain Eloquent Collection — Eloquent's
 * eager-load constraint closures add `where`/`select`, they cannot swap the
 * result for a paginator. This type's 'data' resolver accepts both shapes,
 * so the fallback path's REAL runtime value (a Collection) resolves
 * correctly, while the type still satisfies `isSupported()`'s canonical
 * `instanceof WrapType` wrap-detection check.
 */
class FallbackWrapType extends ObjectType implements WrapType
{
    public function __construct(string $typeName, ?string $customName = null)
    {
        $name = $customName ?: $typeName . 'Wrapped';

        $underlyingType = GraphQL::type($typeName);

        $config = [
            'name' => $name,
            'fields' => $this->getWrapperFields($underlyingType),
        ];

        if (isset($underlyingType->config['model'])) {
            $config['model'] = $underlyingType->config['model'];
        }

        parent::__construct($config); // @phpstan-ignore argument.type ('model' is a Rebing extension to webonyx's ObjectType config)
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function getWrapperFields((NullableType&GraphQLType)|NonNull $underlyingType): array
    {
        return [
            'data' => [
                'type' => GraphQLType::nonNull(GraphQLType::listOf(GraphQLType::nonNull($underlyingType))),
                'resolve' => function (mixed $data): Collection {
                    return $data instanceof LengthAwarePaginator ? $data->getCollection() : $data;
                },
            ],
            'message' => [
                'type' => GraphQLType::string(),
                'selectable' => false,
                'resolve' => fn (): string => 'OK',
            ],
        ];
    }
}
