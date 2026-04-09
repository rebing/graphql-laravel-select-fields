<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Support;

use Closure;
use Rebing\GraphQL\Support\Contracts\ResolverParameterInjector;

/**
 * Injects SelectFields into resolver methods.
 *
 * Supports two patterns:
 * - `Closure $getSelectFields` - returns a lazy factory that creates SelectFields on demand
 * - `SelectFields $fields` - eagerly creates and injects SelectFields
 */
class SelectFieldsParameterInjector implements ResolverParameterInjector
{
    public function supports(string $className): bool
    {
        return Closure::class === $className ||
            SelectFields::class === $className ||
            is_subclass_of($className, SelectFields::class);
    }

    public function resolve(string $className, array $arguments, array $fieldsAndArguments, Field $field): mixed
    {
        if (Closure::class === $className) {
            return function () use ($arguments, $fieldsAndArguments, $field) {
                return $this->createSelectFields($arguments, $fieldsAndArguments, $field);
            };
        }

        return $this->createSelectFields($arguments, $fieldsAndArguments, $field);
    }

    /**
     * @param array<int,mixed> $arguments
     * @param array<string,mixed> $fieldsAndArguments
     */
    protected function createSelectFields(array $arguments, array $fieldsAndArguments, Field $field): SelectFields
    {
        $ctx = $arguments[2] ?? null;

        return new SelectFields($field->type(), $arguments[1], $ctx, $fieldsAndArguments);
    }
}
