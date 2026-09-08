<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use Rebing\GraphQL\Tests\TestCaseDatabase;

abstract class DeferredVariantsTestCase extends TestCaseDatabase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(\Rebing\GraphQL\Support\ArgsVariants\ArgsHasher::class)) {
            self::markTestSkipped('Requires rebing/graphql-laravel >= 10.1 (enriched tree / ArgsHasher).');
        }
    }

    /**
     * The base TestCase only registers `GraphQLServiceProvider` — this
     * suite exercises the deferred-variants machinery end-to-end, which
     * depends on `SelectFieldsServiceProvider`'s wiring: the parameter
     * injector for `Closure $getSelectFields`, the scoped
     * `DeferredVariantsRegistry` binding, and the execution-middleware
     * append that installs `VariantAwareRelationResolver`. Mirrors
     * `MiddlewareWiringTest::getPackageProviders()`.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [
            \Rebing\GraphQL\Support\SelectFieldsServiceProvider::class,
        ]);
    }

    protected function tearDown(): void
    {
        // SelectFieldsServiceProvider::boot() registers a parameter
        // injector on the static \Rebing\GraphQL\Support\Field registry on
        // every app boot; clear it so state doesn't leak into other test
        // files running later in the same PHPUnit process (mirrors
        // SelectFieldsTestCase's and MiddlewareWiringTest's tearDown()).
        \Rebing\GraphQL\Support\Field::clearParameterInjectors();

        parent::tearDown();
    }
}
