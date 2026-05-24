<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\ServiceProvider;
use Rebing\GraphQL\Support\SelectFields\CursorPaginationType;
use Rebing\GraphQL\Support\SelectFields\PaginationType;
use Rebing\GraphQL\Support\SelectFields\SimplePaginationType;

class SelectFieldsServiceProvider extends ServiceProvider
{
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
    }
}
