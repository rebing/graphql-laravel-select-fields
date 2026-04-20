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
                return $this->createSelectFields(SelectFields::class, $arguments, $fieldsAndArguments, $field);
            };
        }

        // $className is guaranteed to be a SelectFields subclass by supports().
        // @phpstan-ignore-next-line argument.type
        return $this->createSelectFields($className, $arguments, $fieldsAndArguments, $field);
    }

    /**
     * @param class-string<SelectFields> $className
     * @param array<int,mixed> $arguments
     * @param array<string,mixed> $fieldsAndArguments
     */
    protected function createSelectFields(string $className, array $arguments, array $fieldsAndArguments, Field $field): SelectFields
    {
        $ctx = $arguments[2] ?? null;

        return new $className($field->type(), $arguments[1], $ctx, $fieldsAndArguments);
    }
}
