<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Unit\Deferred;

use GraphQL\Deferred;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\SelectFields\Deferred\DeferredVariantsRegistry;
use Rebing\GraphQL\Support\SelectFields\Deferred\VariantAwareRelationResolver;
use Rebing\GraphQL\Support\SelectFields\Deferred\VariantSpec;
use Rebing\GraphQL\Tests\Support\Models\Post;
use Rebing\GraphQL\Tests\TestCase;

class VariantAwareRelationResolverTest extends TestCase
{
    /**
     * `testReturnsDeferredOnRegistryHit` constructs a `GraphQL\Deferred`,
     * whose executor is enqueued into webonyx's process-wide static
     * `SyncPromiseQueue` and never drained (this test class is DB-less, so
     * running it would fire a real Eloquent query). Discard — never
     * execute — anything left over so it doesn't leak into later tests
     * running in the same PHPUnit process, especially under random order.
     */
    protected function tearDown(): void
    {
        while (!\GraphQL\Executor\Promise\Adapter\SyncPromiseQueue::isEmpty()) {
            \GraphQL\Executor\Promise\Adapter\SyncPromiseQueue::queue()->dequeue();
        }

        parent::tearDown();
    }

    /**
     * Minimal ResolveInfo stand-in: the resolver only reads parentType->name
     * and fieldName. Build a real ResolveInfo via reflection-free mock.
     */
    private function makeInfo(string $parentTypeName, string $fieldName): ResolveInfo
    {
        $info = self::createStub(ResolveInfo::class);
        $info->fieldName = $fieldName;
        $info->parentType = new ObjectType(['name' => $parentTypeName, 'fields' => ['id' => Type::int()]]);

        return $info;
    }

    public function testDelegatesWhenRootIsNotAModel(): void
    {
        $registry = new DeferredVariantsRegistry;
        $inner = fn ($root, array $args, $ctx, ResolveInfo $info): string => 'inner-called';
        $resolver = new VariantAwareRelationResolver($inner, $registry);

        self::assertSame('inner-called', $resolver(['plain' => 'array'], [], null, $this->makeInfo('Post', 'comments')));
    }

    public function testDelegatesWithoutHashingWhenRegistryEmpty(): void
    {
        // Guards the graphql-laravel 10.0 compat path: with an empty registry
        // the resolver must not touch ArgsHasher at all.
        $registry = new DeferredVariantsRegistry;
        $inner = fn (): string => 'inner-called';
        $resolver = new VariantAwareRelationResolver($inner, $registry);

        self::assertSame('inner-called', $resolver(new Post, ['top' => 3], null, $this->makeInfo('Post', 'comments')));
    }

    public function testReturnsDeferredOnRegistryHit(): void
    {
        $registry = new DeferredVariantsRegistry;
        $registry->register(new VariantSpec(
            'Post',
            'comments',
            'comments',
            ['args' => ['top' => 3], 'fields' => ['id' => ['args' => [], 'fields' => []]]],
            null,
            [],
            null,
            new ObjectType(['name' => 'Comment', 'fields' => ['id' => Type::int()]]),
        ));

        $resolver = new VariantAwareRelationResolver(null, $registry);

        $result = $resolver(new Post, ['top' => 3], null, $this->makeInfo('Post', 'comments'));

        self::assertInstanceOf(Deferred::class, $result);
    }

    /**
     * Resolver hardening (Plan-2 Task 7): a POPULATED registry with a spec
     * that matches (parentType, field, args) exactly must still NOT be
     * consulted when $root isn't a Model — kills the mutant that drops the
     * `instanceof Model` guard (which the empty-registry tests above can't
     * catch, since they never populate the registry at all).
     */
    public function testDelegatesForNonModelRootEvenWithPopulatedRegistry(): void
    {
        $registry = new DeferredVariantsRegistry;
        $registry->register(new VariantSpec(
            'Post',
            'comments',
            'comments',
            ['args' => ['top' => 3], 'fields' => ['id' => ['args' => [], 'fields' => []]]],
            null,
            [],
            null,
            new ObjectType(['name' => 'Comment', 'fields' => ['id' => Type::int()]]),
        ));

        $inner = fn (): string => 'inner-called';
        $resolver = new VariantAwareRelationResolver($inner, $registry);

        $root = ['not' => 'a model'];

        self::assertSame('inner-called', $resolver($root, ['top' => 3], null, $this->makeInfo('Post', 'comments')));
    }

    public function testDelegatesOnRegistryMissWithNonMatchingArgs(): void
    {
        $registry = new DeferredVariantsRegistry;
        $registry->register(new VariantSpec(
            'Post',
            'comments',
            'comments',
            ['args' => ['top' => 3], 'fields' => []],
            null,
            [],
            null,
            new ObjectType(['name' => 'Comment', 'fields' => ['id' => Type::int()]]),
        ));

        $inner = fn (): string => 'inner-called';
        $resolver = new VariantAwareRelationResolver($inner, $registry);

        self::assertSame('inner-called', $resolver(new Post, ['top' => 99], null, $this->makeInfo('Post', 'comments')));
    }

    public function testFallsBackToWebonyxDefaultWhenNoInner(): void
    {
        $registry = new DeferredVariantsRegistry;
        $resolver = new VariantAwareRelationResolver(null, $registry);

        $root = ['title' => 'hello'];

        self::assertSame('hello', $resolver($root, [], null, $this->makeInfo('Post', 'title')));
    }
}
