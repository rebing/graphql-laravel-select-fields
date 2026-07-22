<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Support\SelectFields\Deferred;

final class DeferredVariantsConfig
{
    public static function enabled(): bool
    {
        return (bool) config('graphql.select_fields.deferred_variants', true);
    }

    public static function strict(): bool
    {
        return (bool) config('graphql.select_fields.strict', app()->runningUnitTests());
    }
}
