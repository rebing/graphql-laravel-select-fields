<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Support\SelectFields\Deferred;

use Closure;
use GraphQL\Executor\Executor;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Model;
use Rebing\GraphQL\Support\SelectFields;

/**
 * Default-field-resolver decorator (spec §2.4). On a registry hit for
 * (parentType, field, args-hash) returns a Deferred from the variant's
 * batch loader; otherwise delegates to the wrapped resolver.
 *
 * INVARIANT (recursion safety, spec §1.3/§2.4): the inner delegate is
 * captured at construction. This class must NEVER read
 * config('graphql.defaultFieldResolver') at call time — graphql-laravel's
 * privacy wrapper does a call-time lookup and may receive THIS decorator.
 */
class VariantAwareRelationResolver
{
    /** @var callable|null */
    private $inner;

    public function __construct(
        ?callable $inner,
        private readonly DeferredVariantsRegistry $registry,
    ) {
        $this->inner = $inner;
    }

    /**
     * @param array<string,mixed> $args
     */
    public function __invoke(mixed $root, array $args, mixed $context, ResolveInfo $info): mixed
    {
        // Empty-registry guard doubles as the graphql-laravel 10.0 compat
        // path: ArgsHasher (a 10.1 class) is never touched when no specs
        // were registered.
        if ($root instanceof Model && !$this->registry->isEmpty()) {
            $spec = $this->registry->match($info->parentType->name, $info->fieldName, $args);

            if ($spec) {
                /** @var VariantBatchLoader $loader */
                $loader = $this->registry->loaderFor($spec, static function () use ($spec): VariantBatchLoader {
                    /** @var Closure $constraints */
                    $constraints = SelectFields::getSelectableFieldsAndRelations(
                        $spec->queryArgs,
                        // The verbatim variant entry — getSelectableFieldsAndRelations
                        // reads only ['args'] (custom-query input) and ['fields']
                        // (handleFields iteration); passing the entry untransformed
                        // keeps any future enriched-tree keys intact.
                        $spec->entry,
                        $spec->newParentType,
                        $spec->customQuery,
                        false,
                        $spec->ctx,
                    );

                    return new VariantBatchLoader($spec->relationName, $constraints);
                });

                return $loader->load($root);
            }
        }

        if ($this->inner) {
            return ($this->inner)($root, $args, $context, $info);
        }

        return Executor::defaultFieldResolver($root, $args, $context, $info);
    }

    public function inner(): ?callable
    {
        return $this->inner;
    }
}
