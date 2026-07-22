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
 */
class VariantBatchLoader
{
    /** @var array<string,Model> */
    private array $parents = [];

    /** @var array<string,mixed> */
    private array $results = [];

    private bool $resolved = false;

    public function __construct(
        private readonly string $relationName,
        private readonly Closure $constraints,
    ) {
    }

    public function load(Model $parent): Deferred
    {
        $key = ModelKey::for($parent);
        $this->parents[$key] ??= $parent;

        return new Deferred(function () use ($key): mixed {
            if (!$this->resolved) {
                $this->resolve();
            }

            return $this->results[$key];
        });
    }

    private function resolve(): void
    {
        $this->resolved = true;

        if ([] === $this->parents) {
            return;
        }

        /** @var array<string,Model> $clones */
        $clones = [];

        foreach ($this->parents as $key => $parent) {
            $clone = clone $parent;
            $clone->setRelations([]);
            $clones[$key] = $clone;
        }

        (new EloquentCollection(array_values($clones)))
            ->load([$this->relationName => $this->constraints]);

        foreach ($clones as $key => $clone) {
            $this->results[$key] = $clone->getRelation($this->relationName);
        }
    }
}
