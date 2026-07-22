<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Support\SelectFields\Deferred;

use Closure;
use GraphQL\Type\Definition\Type as GraphqlType;

/**
 * Bridges SelectFields' handleFields() to the registry: decides variant
 * support (spec §2.1/§2.1a) and registers one spec per active variant.
 */
final class DeferredVariantsRegistrar
{
    /**
     * @param array<int|string,mixed> $field The enriched tree entry (carries 'argsVariants')
     * @param array<string,mixed> $queryArgs
     */
    public static function registerVariants(
        array $field,
        string $parentTypeName,
        string $fieldName,
        string $relationName,
        ?Closure $customQuery,
        array $queryArgs,
        mixed $ctx,
        GraphqlType $newParentType,
    ): void {
        $registry = app(DeferredVariantsRegistry::class);

        foreach ($field['argsVariants'] as $variant) {
            $registry->register(new VariantSpec(
                $parentTypeName,
                $fieldName,
                $relationName,
                $variant,
                $customQuery,
                $queryArgs,
                $ctx,
                $newParentType,
            ));
        }
    }

    /**
     * Fields with a custom resolver are NOT "unsupported" — they take the
     * plain 1.0 path (legacy $with, no registry, NO warning): the resolver
     * already receives correct per-node arguments from the executor, so
     * there is nothing to warn about. Check that separately, before this.
     *
     * @param array<string,mixed> $fieldConfig
     */
    public static function hasCustomResolver(array $fieldConfig): bool
    {
        return isset($fieldConfig['resolve']);
    }

    /**
     * Unsupported (spec §2.1a → legacy fallback + WARNING): MorphTo
     * relations, paginated/wrapped relation targets, and privacy-carrying
     * fields.
     *
     * - Wrap detection: the package's canonical WrapType marker PLUS, as
     *   belt-and-braces, graphql-laravel's base pagination classes (covers
     *   users whose pagination types are not the select-fields subclasses).
     * - Privacy (spec: privacy contract): denial outcomes are per-row and
     *   unobservable from select-fields, so these fields never register
     *   specs — keeping the unconsumed-spec check fully strict for
     *   everything that IS registered.
     * - Interface/union parent contexts never reach the model-relation
     *   branch, so they stay legacy implicitly.
     *
     * @param array<string,mixed> $fieldConfig
     */
    public static function isSupported(array $fieldConfig, object $relation, GraphqlType $newParentType): bool
    {
        if (isset($fieldConfig['privacy'])) {
            return false;
        }

        if ($relation instanceof \Illuminate\Database\Eloquent\Relations\MorphTo) {
            return false;
        }

        $innermost = $newParentType instanceof \GraphQL\Type\Definition\WrappingType
            ? $newParentType->getInnermostType()
            : $newParentType;

        // FQCNs verified against graphql-laravel sources:
        // src/Support/PaginationType.php, src/Support/SimplePaginationType.php,
        // src/Support/CursorPaginationType.php — all `namespace
        // Rebing\GraphQL\Support;` (the select-fields pagination subclasses
        // in src/Support/SelectFields/ extend these AND implement WrapType).
        if ($innermost instanceof \Rebing\GraphQL\Support\Contracts\WrapType ||
            $innermost instanceof \Rebing\GraphQL\Support\PaginationType ||
            $innermost instanceof \Rebing\GraphQL\Support\SimplePaginationType ||
            $innermost instanceof \Rebing\GraphQL\Support\CursorPaginationType
        ) {
            return false;
        }

        return true;
    }

    /**
     * @param array<int|string,mixed> $field
     */
    public static function warnUnsupported(string $parentTypeName, string $fieldName, array $field): void
    {
        \Illuminate\Support\Facades\Log::warning('SelectFields: argument variants on an unsupported relation — falling back to legacy merged eager load', [
            'parentType' => $parentTypeName,
            'field' => $fieldName,
            'args' => array_column($field['argsVariants'], 'args'),
            'reason' => 'paginated/wrapped relation target, MorphTo relation, or privacy-protected field (per-row denial outcomes are unobservable from select-fields)',
        ]);

        if (DeferredVariantsConfig::strict()) {
            throw new DeferredVariantsException(\sprintf(
                'Unsupported deferred variant on %s.%s — see the preceding log warning.',
                $parentTypeName,
                $fieldName,
            ));
        }
    }
}
