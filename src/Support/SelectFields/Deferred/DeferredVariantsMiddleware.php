<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Support\SelectFields\Deferred;

use Closure;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Type\Schema;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Rebing\GraphQL\Support\ExecutionMiddleware\AbstractExecutionMiddleware;
use Rebing\GraphQL\Support\OperationParams;
use Throwable;

/**
 * Installs the VariantAwareRelationResolver decorator at execution start
 * (spec §2.4: decoration at execution time is immune to provider boot order
 * and to user config set in application providers) and flushes the registry
 * afterwards without ever masking the original exception.
 */
class DeferredVariantsMiddleware extends AbstractExecutionMiddleware
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly DeferredVariantsRegistry $registry,
    ) {
    }

    public function handle(string $schemaName, Schema $schema, OperationParams $params, $rootValue, $contextValue, Closure $next): ExecutionResult
    {
        if (!DeferredVariantsConfig::enabled()) {
            return $next($schemaName, $schema, $params, $rootValue, $contextValue);
        }

        $current = $this->config->get('graphql.defaultFieldResolver');

        if (!$current instanceof VariantAwareRelationResolver) {
            $this->config->set(
                'graphql.defaultFieldResolver',
                new VariantAwareRelationResolver(
                    null === $current ? null : $this->asCallable($current),
                    $this->registry,
                ),
            );
        } elseif ($current->registry() !== $this->registry) {
            // Decorator identity (spec §2.2): long-lived non-Octane runtimes
            // (e.g. queue workers) flush scoped bindings but keep config —
            // the persisted decorator then holds a stale registry and every
            // match would miss, degrading to unconstrained lazy loads.
            // Re-wrap the ORIGINAL inner resolver (never a decorator inside
            // a decorator) around the current scoped registry.
            $inner = $current->inner();

            while ($inner instanceof VariantAwareRelationResolver) {
                $inner = $inner->inner();
            }

            $this->config->set(
                'graphql.defaultFieldResolver',
                new VariantAwareRelationResolver($inner, $this->registry),
            );
        }

        // Armed handshake (spec §2.2): handleFields diverts/observes only
        // when armed, so executions bypassing this middleware (per-schema
        // execution_middleware lists configured after provider boot) keep
        // pure legacy behavior. Cleared again in flush().
        $this->registry->arm();

        try {
            $result = $next($schemaName, $schema, $params, $rootValue, $contextValue);
        } catch (Throwable $e) {
            $this->registry->flush(false);

            throw $e;
        }

        // GraphQL errors are carried inside the result, not thrown — treat
        // an errored result as unsuccessful for unconsumed-spec purposes.
        $this->registry->flush([] === $result->errors);

        return $result;
    }

    private function asCallable(mixed $resolver): callable
    {
        if (\is_callable($resolver)) {
            return $resolver;
        }

        throw new DeferredVariantsException('graphql.defaultFieldResolver is set but not callable.');
    }
}
