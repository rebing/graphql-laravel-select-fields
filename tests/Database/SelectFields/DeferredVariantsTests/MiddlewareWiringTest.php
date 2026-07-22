<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use Rebing\GraphQL\Support\Field;
use Rebing\GraphQL\Support\SelectFields\Deferred\DeferredVariantsMiddleware;
use Rebing\GraphQL\Support\SelectFields\Deferred\DeferredVariantsRegistry;
use Rebing\GraphQL\Support\SelectFields\Deferred\VariantAwareRelationResolver;
use Rebing\GraphQL\Support\SelectFieldsServiceProvider;
use Rebing\GraphQL\Tests\Support\Queries\PostsListOfWithSelectFieldsAndModelQuery;
use Rebing\GraphQL\Tests\Support\Types\PostWithModelType;
use Rebing\GraphQL\Tests\TestCaseDatabase;

class MiddlewareWiringTest extends TestCaseDatabase
{
    /**
     * The base test case only registers `GraphQLServiceProvider` — this
     * suite is specifically about `SelectFieldsServiceProvider`'s wiring
     * (the middleware append in `boot()`, the scoped registry binding in
     * `register()`), so it must be loaded too.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [
            SelectFieldsServiceProvider::class,
        ]);
    }

    protected function tearDown(): void
    {
        // SelectFieldsServiceProvider::boot() registers a parameter
        // injector on the static \Rebing\GraphQL\Support\Field registry on
        // every app boot; clear it so state doesn't leak into other test
        // files running later in the same PHPUnit process (mirrors
        // SelectFieldsTestCase's own tearDown()).
        Field::clearParameterInjectors();

        parent::tearDown();
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('graphql.schemas.default', [
            'query' => [PostsListOfWithSelectFieldsAndModelQuery::class],
        ]);

        // A second schema carrying its OWN `execution_middleware` list (a
        // copy of the global default, taken before the provider boots and
        // appends to it) so `testPerSchemaMiddlewareListAlsoGetsAppended`
        // is meaningful: per-schema lists REPLACE the global one entirely
        // (see \Rebing\GraphQL\GraphQL::executionMiddleware()), so without
        // a schema like this every schema's list would be `null` and the
        // provider's per-schema loop would be a no-op.
        $app['config']->set('graphql.schemas.withOwnMiddleware', [
            'query' => [PostsListOfWithSelectFieldsAndModelQuery::class],
            'execution_middleware' => $app['config']->get('graphql.execution_middleware'),
        ]);

        $app['config']->set('graphql.types', [
            PostWithModelType::class,
        ]);
    }

    public function testMiddlewareIsAppendedToGlobalList(): void
    {
        self::assertContains(DeferredVariantsMiddleware::class, config('graphql.execution_middleware'));
    }

    public function testResolverIsWrappedDuringExecutionAndUserResolverPreserved(): void
    {
        $userResolver = static fn ($root, array $args, $ctx, $info) => null;
        config(['graphql.defaultFieldResolver' => $userResolver]);

        $this->httpGraphql('{ postsListOfWithSelectFieldsAndModel { id } }');

        $wrapped = config('graphql.defaultFieldResolver');
        self::assertInstanceOf(VariantAwareRelationResolver::class, $wrapped);
        self::assertSame($userResolver, $wrapped->inner());
    }

    public function testWrappingIsIdempotentAcrossExecutions(): void
    {
        $this->httpGraphql('{ postsListOfWithSelectFieldsAndModel { id } }');
        $first = config('graphql.defaultFieldResolver');
        $this->httpGraphql('{ postsListOfWithSelectFieldsAndModel { id } }');
        $second = config('graphql.defaultFieldResolver');

        self::assertInstanceOf(VariantAwareRelationResolver::class, $second);
        self::assertNotInstanceOf(VariantAwareRelationResolver::class, $second->inner());
        self::assertSame($first, $second);
    }

    public function testFlagOffLeavesConfigUntouched(): void
    {
        config(['graphql.select_fields.deferred_variants' => false]);
        config(['graphql.defaultFieldResolver' => null]);

        $this->httpGraphql('{ postsListOfWithSelectFieldsAndModel { id } }');

        self::assertNull(config('graphql.defaultFieldResolver'));
    }

    public function testRegistryIsFlushedAfterExecution(): void
    {
        $this->httpGraphql('{ postsListOfWithSelectFieldsAndModel { id } }');

        self::assertTrue($this->app->make(DeferredVariantsRegistry::class)->isEmpty());
    }

    public function testPerSchemaMiddlewareListAlsoGetsAppended(): void
    {
        // The provider must handle per-schema overrides too (they replace the
        // global list entirely in GraphQL::executionMiddleware()).
        $schemas = config('graphql.schemas');
        self::assertNotEmpty($schemas);

        foreach ($schemas as $schema) {
            if (\is_array($schema['execution_middleware'] ?? null)) {
                self::assertContains(DeferredVariantsMiddleware::class, $schema['execution_middleware']);
            }
        }
    }
}
