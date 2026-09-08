<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Support\SelectFields\Deferred;

use Closure;
use GraphQL\Deferred;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

/**
 * Collect-then-batch loader for one relation argument-variant (spec §2.3).
 *
 * Relations are loaded onto DETACHED CLONES of the parents, so user-visible
 * models are never mutated and no correctness depends on Eloquent's relation
 * cache or on promise sequencing (SyncPromiseAdapter's sequential execution
 * is an optimization fact only, not an invariant we rely on).
 *
 * The constraint closure is built LAZILY at the first force via
 * $constraintsFactory (spec §2.3, force-time constraints): spec-subtree
 * merges landing after the first resolver hit — later root branches, legacy
 * observations — must be honored in the SQL. Parents collected after a
 * prior force are re-batched on the next force (one additional base query
 * per late wave, never an undefined-result read).
 */
class VariantBatchLoader
{
    /** @var array<string,Model> Parents collected but not yet batch-loaded. */
    private array $pending = [];

    /**
     * Parents already batch-loaded. Kept for the loader's lifetime so
     * spl_object_id-based ModelKeys can never be reused (see ModelKey).
     *
     * @var array<string,Model>
     */
    private array $loaded = [];

    /** @var array<string,mixed> */
    private array $results = [];

    /** Built once, at the first force. */
    private ?Closure $constraints = null;

    /**
     * @param Closure():Closure $constraintsFactory Returns the relation
     *                                              constraint closure; invoked once, at the first force
     */
    public function __construct(
        private readonly string $relationName,
        private readonly Closure $constraintsFactory,
    ) {
    }

    public function load(Model $parent): Deferred
    {
        $key = ModelKey::for($parent);

        if (!isset($this->loaded[$key])) {
            $this->pending[$key] ??= $parent;
        }

        return new Deferred(function () use ($key): mixed {
            if (!\array_key_exists($key, $this->results)) {
                $this->resolve();
            }

            return $this->results[$key];
        });
    }

    /**
     * Batch-load the relation for all currently pending parents. Results
     * accumulate across waves; already-loaded parents are never re-queried.
     */
    private function resolve(): void
    {
        $parents = $this->pending;
        $this->pending = [];

        if ([] === $parents) {
            return;
        }

        $this->constraints ??= ($this->constraintsFactory)();

        /** @var array<string,Model> $clones */
        $clones = [];

        foreach ($parents as $key => $parent) {
            $clone = clone $parent;
            $clone->setRelations([]);
            $clones[$key] = $clone;
        }

        (new EloquentCollection(array_values($clones)))
            ->load([$this->relationName => $this->constraints]);

        foreach ($clones as $key => $clone) {
            $this->results[$key] = $clone->getRelation($this->relationName);
            $this->loaded[$key] = $parents[$key];
        }
    }
}
