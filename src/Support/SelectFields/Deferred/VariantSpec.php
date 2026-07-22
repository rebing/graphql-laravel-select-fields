<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Support\SelectFields\Deferred;

use Closure;
use GraphQL\Type\Definition\Type as GraphqlType;

/**
 * One argument-variant of an Eloquent relation field, registered by
 * SelectFields when the enriched tree carries 'argsVariants' (spec §2.2).
 *
 * The variant tree entry is stored VERBATIM (currently `['args' => …,
 * 'fields' => …]` per the Part 1 contract) and handed to
 * getSelectableFieldsAndRelations() untransformed — future-proof against
 * additional keys appearing in the enriched tree.
 */
final class VariantSpec
{
    /** Whether the resolver matched this spec during execution. */
    public bool $consumed = false;

    /**
     * @param array<string,mixed> $entry The verbatim variant entry from the enriched tree
     * @param array<string,mixed> $queryArgs Root query arguments
     */
    public function __construct(
        public readonly string $parentTypeName,
        public readonly string $fieldName,
        public readonly string $relationName,
        public array $entry,
        public readonly ?Closure $customQuery,
        public readonly array $queryArgs,
        public readonly mixed $ctx,
        public readonly GraphqlType $newParentType,
    ) {
    }

    /** @return array<string,mixed> */
    public function args(): array
    {
        return $this->entry['args'] ?? [];
    }

    /** @return array<string,mixed> */
    public function fields(): array
    {
        return $this->entry['fields'] ?? [];
    }

    /**
     * Deep-union another occurrence's subtree into this spec (same variant
     * reached from two resolution branches): union of fields; nested
     * 'argsVariants' merged RECURSIVELY BY HASH (a plain array-union would
     * keep the first branch's variant wholesale and silently drop the second
     * branch's sub-selection).
     *
     * @param array<string,mixed> $fields
     */
    public function mergeFields(array $fields): void
    {
        $this->entry['fields'] = self::unionFields($this->fields(), $fields);
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array<string,mixed>
     */
    private static function unionFields(array $a, array $b): array
    {
        foreach ($b as $name => $entry) {
            if (!isset($a[$name]) || !\is_array($a[$name]) || !\is_array($entry)) {
                $a[$name] = $entry;

                continue;
            }

            if (isset($entry['fields']) && \is_array($entry['fields']) && \is_array($a[$name]['fields'] ?? null)) {
                $a[$name]['fields'] = self::unionFields($a[$name]['fields'], $entry['fields']);
            }

            if (isset($entry['argsVariants']) && \is_array($entry['argsVariants'])) {
                $existing = \is_array($a[$name]['argsVariants'] ?? null) ? $a[$name]['argsVariants'] : [];

                foreach ($entry['argsVariants'] as $hash => $variant) {
                    if (isset($existing[$hash])) {
                        $existing[$hash]['fields'] = self::unionFields(
                            \is_array($existing[$hash]['fields'] ?? null) ? $existing[$hash]['fields'] : [],
                            \is_array($variant['fields'] ?? null) ? $variant['fields'] : [],
                        );
                    } else {
                        $existing[$hash] = $variant;
                    }
                }

                $a[$name]['argsVariants'] = $existing;
            }
        }

        return $a;
    }
}
