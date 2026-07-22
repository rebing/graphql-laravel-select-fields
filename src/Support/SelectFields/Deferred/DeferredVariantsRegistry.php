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

    /**
     * Cross-position observations (spec §2.2): every legacy Eloquent-relation
     * position handleFields processes, stored RAW — [parentTypeName,
     * fieldName, args, subtreeFields]. Hashing only happens in register()
     * (i.e. once specs exist, which implies graphql-laravel >= 10.1): on
     * 10.0 ArgsHasher does not exist and observations must never touch it.
     *
     * @var array<int,array{string,string,array<string,mixed>,array<string,mixed>}>
     */
    private array $observations = [];

    /**
     * Armed handshake (spec §2.2): set by the middleware at execution
     * start; handleFields diverts/observes only when armed, so executions
     * bypassing the middleware keep pure legacy behavior.
     */
    private bool $armed = false;

    public function arm(): void
    {
        $this->armed = true;
    }

    public function isArmed(): bool
    {
        return $this->armed;
    }

    public function register(VariantSpec $spec): void
    {
        $key = $this->key($spec->parentTypeName, $spec->fieldName, ArgsHasher::hash($spec->args()));

        if (isset($this->specs[$key])) {
            $this->specs[$key]->mergeFields($spec->fields());
        } else {
            $this->specs[$key] = $spec;
        }

        // Back-merge all stored observations matching this key (spec §2.2:
        // a legacy position processed BEFORE this spec existed would
        // otherwise be intercepted with a subtree lacking its own
        // sub-selection). Raw observations are hashed here, never earlier.
        foreach ($this->observations as $i => [$parentTypeName, $fieldName, $args, $subtreeFields]) {
            if ($this->key($parentTypeName, $fieldName, ArgsHasher::hash($args)) === $key) {
                $this->specs[$key]->mergeFields($subtreeFields);
                unset($this->observations[$i]);
            }
        }
    }

    /**
     * Record a legacy (non-variant) Eloquent-relation position (spec §2.2,
     * cross-position safety): if a spec for the same (type, field, args)
     * key exists — registered by ANOTHER position — the resolver will
     * intercept this position too, so its sub-selection must be merged into
     * the spec; otherwise the observation is stored (raw, hash-free) for a
     * potential later register() to back-merge. Observations are never
     * counted as unconsumed and are cleared on flush().
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $subtreeFields
     */
    public function observe(string $parentTypeName, string $fieldName, array $args, array $subtreeFields): void
    {
        if (!$this->armed) {
            return;
        }

        if ([] !== $this->specs) {
            $key = $this->key($parentTypeName, $fieldName, ArgsHasher::hash($args));

            if (isset($this->specs[$key])) {
                $this->specs[$key]->mergeFields($subtreeFields);

                return;
            }
        }

        $this->observations[] = [$parentTypeName, $fieldName, $args, $subtreeFields];
    }

    /**
     * @param array<string,mixed> $args
     */
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
        $this->observations = [];
        $this->armed = false;

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
