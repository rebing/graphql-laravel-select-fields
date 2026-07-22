<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Unit\Deferred;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Illuminate\Support\Facades\Log;
use Rebing\GraphQL\Support\SelectFields\Deferred\DeferredVariantsException;
use Rebing\GraphQL\Support\SelectFields\Deferred\DeferredVariantsRegistry;
use Rebing\GraphQL\Support\SelectFields\Deferred\VariantSpec;
use Rebing\GraphQL\Tests\TestCase;
use stdClass;

class DeferredVariantsRegistryTest extends TestCase
{
    /**
     * @param array<string,mixed> $args
     * @param array<string,mixed> $fields
     */
    private function makeSpec(array $args = ['top' => 3], array $fields = ['id' => true]): VariantSpec
    {
        return new VariantSpec(
            'Post',
            'comments',
            'comments',
            ['args' => $args, 'fields' => $fields],
            null,
            [],
            null,
            new ObjectType(['name' => 'Comment', 'fields' => ['id' => Type::int()]]),
        );
    }

    public function testMatchReturnsRegisteredSpecForSameArgs(): void
    {
        $registry = new DeferredVariantsRegistry;
        $spec = $this->makeSpec(['top' => 3]);
        $registry->register($spec);

        self::assertFalse($registry->isEmpty());
        self::assertSame($spec, $registry->match('Post', 'comments', ['top' => 3]));
        self::assertNull($registry->match('Post', 'comments', ['top' => 4]));
        self::assertNull($registry->match('Post', 'likes', ['top' => 3]));
        self::assertNull($registry->match('User', 'comments', ['top' => 3]));
    }

    public function testMatchIsAssocKeyOrderInsensitive(): void
    {
        $registry = new DeferredVariantsRegistry;
        $registry->register($this->makeSpec(['a' => 1, 'b' => 2]));

        self::assertNotNull($registry->match('Post', 'comments', ['b' => 2, 'a' => 1]));
    }

    public function testRegisterMergesFieldsOnKeyCollision(): void
    {
        $registry = new DeferredVariantsRegistry;
        $registry->register($this->makeSpec(['top' => 3], ['id' => ['args' => [], 'fields' => []]]));
        $registry->register($this->makeSpec(['top' => 3], ['body' => ['args' => [], 'fields' => []]]));

        $spec = $registry->match('Post', 'comments', ['top' => 3]);
        self::assertNotNull($spec);
        self::assertArrayHasKey('id', $spec->fields());
        self::assertArrayHasKey('body', $spec->fields());
    }

    public function testRegisterMergesNestedArgsVariantsByHashRecursively(): void
    {
        // Same registry key registered from two resolution branches whose
        // subtrees carry the SAME nested variant hash with DIFFERENT
        // sub-selections — a plain '+' union would drop 'body'.
        $hash = \Rebing\GraphQL\Support\ArgsVariants\ArgsHasher::hash(['deep' => 1]);

        $registry = new DeferredVariantsRegistry;
        $registry->register($this->makeSpec(['top' => 3], [
            'likes' => ['args' => [], 'fields' => [], 'argsVariants' => [
                $hash => ['args' => ['deep' => 1], 'fields' => ['id' => ['args' => [], 'fields' => []]]],
            ]],
        ]));
        $registry->register($this->makeSpec(['top' => 3], [
            'likes' => ['args' => [], 'fields' => [], 'argsVariants' => [
                $hash => ['args' => ['deep' => 1], 'fields' => ['body' => ['args' => [], 'fields' => []]]],
            ]],
        ]));

        $spec = $registry->match('Post', 'comments', ['top' => 3]);
        self::assertNotNull($spec);
        $variant = $spec->fields()['likes']['argsVariants'][$hash];
        self::assertArrayHasKey('id', $variant['fields']);
        self::assertArrayHasKey('body', $variant['fields']);
    }

    public function testRecursiveMergePreservesDeeperNestedArgsVariants(): void
    {
        // The hash-merged variant's OWN subtree may itself contain
        // argsVariants one level deeper — the recursion must carry them
        // through the union, not just plain fields.
        $hash = \Rebing\GraphQL\Support\ArgsVariants\ArgsHasher::hash(['deep' => 1]);
        $deeperHash = \Rebing\GraphQL\Support\ArgsVariants\ArgsHasher::hash(['deepest' => 9]);

        $registry = new DeferredVariantsRegistry;
        $registry->register($this->makeSpec(['top' => 3], [
            'likes' => ['args' => [], 'fields' => [], 'argsVariants' => [
                $hash => ['args' => ['deep' => 1], 'fields' => [
                    'reactions' => ['args' => [], 'fields' => [], 'argsVariants' => [
                        $deeperHash => ['args' => ['deepest' => 9], 'fields' => ['id' => ['args' => [], 'fields' => []]]],
                    ]],
                ]],
            ]],
        ]));
        $registry->register($this->makeSpec(['top' => 3], [
            'likes' => ['args' => [], 'fields' => [], 'argsVariants' => [
                $hash => ['args' => ['deep' => 1], 'fields' => [
                    'reactions' => ['args' => [], 'fields' => [], 'argsVariants' => [
                        $deeperHash => ['args' => ['deepest' => 9], 'fields' => ['kind' => ['args' => [], 'fields' => []]]],
                    ]],
                ]],
            ]],
        ]));

        $spec = $registry->match('Post', 'comments', ['top' => 3]);
        self::assertNotNull($spec);
        $deepest = $spec->fields()['likes']['argsVariants'][$hash]['fields']['reactions']['argsVariants'][$deeperHash];
        self::assertArrayHasKey('id', $deepest['fields']);
        self::assertArrayHasKey('kind', $deepest['fields']);
    }

    public function testFlushSuccessfulWarnsAndClearsOnUnconsumedSpecs(): void
    {
        config(['graphql.select_fields.strict' => false]);
        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'unconsumed') &&
                'Post' === $context['parentType'] &&
                'comments' === $context['field'] &&
                ['top' => 3] === $context['args'];
        });

        $registry = new DeferredVariantsRegistry;
        $registry->register($this->makeSpec(['top' => 3]));
        $registry->flush(true);

        self::assertTrue($registry->isEmpty());
    }

    public function testFlushSuccessfulThrowsInStrictModeOnUnconsumedSpecs(): void
    {
        config(['graphql.select_fields.strict' => true]);
        Log::shouldReceive('warning')->once();

        $registry = new DeferredVariantsRegistry;
        $registry->register($this->makeSpec());

        $this->expectException(DeferredVariantsException::class);
        $registry->flush(true);
    }

    public function testFlushAfterFailureNeverThrowsNorWarns(): void
    {
        config(['graphql.select_fields.strict' => true]);
        Log::shouldReceive('warning')->never();

        $registry = new DeferredVariantsRegistry;
        $registry->register($this->makeSpec());
        $registry->flush(false);

        self::assertTrue($registry->isEmpty());
    }

    public function testConsumedSpecsDoNotTriggerUnconsumedHandling(): void
    {
        config(['graphql.select_fields.strict' => true]);
        Log::shouldReceive('warning')->never();

        $registry = new DeferredVariantsRegistry;
        $registry->register($this->makeSpec(['top' => 3]));
        $registry->match('Post', 'comments', ['top' => 3]);
        $registry->flush(true);

        self::assertTrue($registry->isEmpty());
    }

    public function testLoaderForMemoizesPerSpec(): void
    {
        $registry = new DeferredVariantsRegistry;
        $spec = $this->makeSpec();
        $registry->register($spec);

        $calls = 0;
        $make = function () use (&$calls): object {
            $calls++;

            return new stdClass;
        };

        $first = $registry->loaderFor($spec, $make);
        $second = $registry->loaderFor($spec, $make);

        self::assertSame($first, $second);
        self::assertSame(1, $calls);
    }
}
