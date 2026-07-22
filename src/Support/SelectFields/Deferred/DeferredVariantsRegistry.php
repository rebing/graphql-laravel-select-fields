<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Support\SelectFields\Deferred;

use Closure;
use Illuminate\Support\Facades\Log;
use Rebing\GraphQL\Support\ArgsVariants\ArgsHasher;

/**
 * Per-execution registry of relation argument-variants (spec §2.2).
 *
 * Lifetime: one GraphQL operation. Registered as a scoped singleton and
 * flushed by DeferredVariantsMiddleware in a finally block.
 */
class DeferredVariantsRegistry
{
    /** @var array<string,VariantSpec> */
    private array $specs = [];

    /** @var array<string,object> */
    private array $loaders = [];

    public function register(VariantSpec $spec): void
    {
        $key = $this->key($spec->parentTypeName, $spec->fieldName, ArgsHasher::hash($spec->args()));

        if (isset($this->specs[$key])) {
            $this->specs[$key]->mergeFields($spec->fields());

            return;
        }

        $this->specs[$key] = $spec;
    }

    /** @param array<string,mixed> $args */
    public function match(string $parentTypeName, string $fieldName, array $args): ?VariantSpec
    {
        $key = $this->key($parentTypeName, $fieldName, ArgsHasher::hash($args));
        $spec = $this->specs[$key] ?? null;

        if ($spec) {
            $spec->consumed = true;
        }

        return $spec;
    }

    public function isEmpty(): bool
    {
        return [] === $this->specs;
    }

    /**
     * Memoize one loader per spec; the factory is supplied by the resolver
     * so this class stays free of loading concerns.
     */
    public function loaderFor(VariantSpec $spec, Closure $makeLoader): object
    {
        $key = $this->key($spec->parentTypeName, $spec->fieldName, ArgsHasher::hash($spec->args()));

        return $this->loaders[$key] ??= $makeLoader();
    }

    /**
     * Clear all state. On successful execution, unconsumed specs indicate
     * something bypassed the resolver: warn (throw in strict mode). During
     * exceptional unwind ($successful = false) never warn nor throw — the
     * original exception must not be masked (spec: error handling).
     */
    public function flush(bool $successful): void
    {
        // Fully strict: everything registered must be consumed on success.
        // (Privacy-carrying fields never register specs — spec: privacy
        // contract — so no exemption exists here by design.)
        $unconsumed = $successful
            ? array_filter($this->specs, static fn (VariantSpec $s): bool => !$s->consumed)
            : [];

        $this->specs = [];
        $this->loaders = [];

        if ([] === $unconsumed) {
            return;
        }

        foreach ($unconsumed as $spec) {
            Log::warning('SelectFields deferred variant spec was registered but never consumed (unconsumed spec)', [
                'parentType' => $spec->parentTypeName,
                'field' => $spec->fieldName,
                'args' => $spec->args(),
                'reason' => 'no resolver invocation matched this variant; a custom resolver or resolver wrapping issue may have bypassed the variant mechanism',
            ]);
        }

        if (DeferredVariantsConfig::strict()) {
            $spec = reset($unconsumed);

            throw new DeferredVariantsException(\sprintf(
                'Unconsumed deferred variant spec for %s.%s — see the preceding log warning(s).',
                $spec->parentTypeName,
                $spec->fieldName,
            ));
        }
    }

    private function key(string $parentTypeName, string $fieldName, string $argsHash): string
    {
        return "{$parentTypeName}:{$fieldName}:{$argsHash}";
    }
}
