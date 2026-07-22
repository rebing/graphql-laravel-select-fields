<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\ServiceProvider;
use Rebing\GraphQL\Support\SelectFields\CursorPaginationType;
use Rebing\GraphQL\Support\SelectFields\Deferred;
use Rebing\GraphQL\Support\SelectFields\PaginationType;
use Rebing\GraphQL\Support\SelectFields\SimplePaginationType;

class SelectFieldsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Deferred\DeferredVariantsRegistry::class);
    }

    public function boot(): void
    {
        // Register the parameter injector so that Closure and SelectFields
        // type-hints in resolver methods are resolved to SelectFields instances.
        Field::registerParameterInjector(new SelectFieldsParameterInjector);

        // Override pagination type config to use SelectFields-aware subclasses
        // that implement WrapType and mark metadata fields as non-selectable.
        /** @var ConfigRepository $config */
        $config = $this->app->make('config');

        // Only override if the user has not already set a custom pagination type
        if (PaginationType::class !== $config->get('graphql.pagination_type') &&
            \Rebing\GraphQL\Support\PaginationType::class === $config->get('graphql.pagination_type')) {
            $config->set('graphql.pagination_type', PaginationType::class);
        }

        if (SimplePaginationType::class !== $config->get('graphql.simple_pagination_type') &&
            \Rebing\GraphQL\Support\SimplePaginationType::class === $config->get('graphql.simple_pagination_type')) {
            $config->set('graphql.simple_pagination_type', SimplePaginationType::class);
        }

        if (CursorPaginationType::class !== $config->get('graphql.cursor_pagination_type') &&
            \Rebing\GraphQL\Support\CursorPaginationType::class === $config->get('graphql.cursor_pagination_type')) {
            $config->set('graphql.cursor_pagination_type', CursorPaginationType::class);
        }

        // Deferred args-variants (spec Part 2): append the execution
        // middleware to the global list AND to every configured per-schema
        // list (per-schema lists override the global one entirely).
        $middlewareClass = Deferred\DeferredVariantsMiddleware::class;

        $global = $config->get('graphql.execution_middleware');

        if (\is_array($global) && !\in_array($middlewareClass, $global, true)) {
            $global[] = $middlewareClass;
            $config->set('graphql.execution_middleware', $global);
        }

        foreach ((array) $config->get('graphql.schemas', []) as $schemaName => $schema) {
            $perSchema = $schema['execution_middleware'] ?? null;

            if (\is_array($perSchema) && !\in_array($middlewareClass, $perSchema, true)) {
                $perSchema[] = $middlewareClass;
                $config->set("graphql.schemas.{$schemaName}.execution_middleware", $perSchema);
            }
        }
    }
}
