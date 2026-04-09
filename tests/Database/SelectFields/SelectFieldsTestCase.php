<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields;

use Rebing\GraphQL\Support\Field;
use Rebing\GraphQL\Support\SelectFields\CursorPaginationType;
use Rebing\GraphQL\Support\SelectFields\PaginationType;
use Rebing\GraphQL\Support\SelectFields\SimplePaginationType;
use Rebing\GraphQL\Support\SelectFieldsParameterInjector;
use Rebing\GraphQL\Tests\TestCaseDatabase;

/**
 * Base class for all SelectFields database tests.
 *
 * Registers the SelectFieldsParameterInjector so that Closure and SelectFields
 * type-hints in resolve() methods work during tests, and configures the
 * SelectFields-aware pagination types.
 */
abstract class SelectFieldsTestCase extends TestCaseDatabase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Use SelectFields-aware pagination types that implement WrapType
        // and mark metadata fields as selectable => false.
        $app['config']->set('graphql.pagination_type', PaginationType::class);
        $app['config']->set('graphql.simple_pagination_type', SimplePaginationType::class);
        $app['config']->set('graphql.cursor_pagination_type', CursorPaginationType::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Field::registerParameterInjector(new SelectFieldsParameterInjector);
    }

    protected function tearDown(): void
    {
        Field::clearParameterInjectors();

        parent::tearDown();
    }
}
