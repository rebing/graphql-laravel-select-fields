<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use Closure;
use GraphQL\Executor\Promise\Adapter\SyncPromiseQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Rebing\GraphQL\Support\SelectFields\Deferred\ModelKey;
use Rebing\GraphQL\Support\SelectFields\Deferred\VariantBatchLoader;
use Rebing\GraphQL\Tests\Support\Models\Comment;
use Rebing\GraphQL\Tests\Support\Models\Post;
use Rebing\GraphQL\Tests\Support\Traits\SqlAssertionTrait;
use Rebing\GraphQL\Tests\TestCaseDatabase;

class VariantBatchLoaderTest extends TestCaseDatabase
{
    use SqlAssertionTrait;

    public function testModelKeyIdentity(): void
    {
        /** @var Post $post */
        $post = Post::factory()->create();
        /** @var Post $again */
        $again = Post::query()->findOrFail($post->getKey());
        $unsaved = new Post;

        self::assertSame(ModelKey::for($post), ModelKey::for($again));
        self::assertNotSame(ModelKey::for($post), ModelKey::for($unsaved));
        self::assertNotSame(ModelKey::for($unsaved), ModelKey::for(new Post));
    }

    public function testBatchLoadsOnceAndNeverMutatesParents(): void
    {
        /** @var EloquentCollection<int,Post> $posts */
        $posts = Post::factory()->count(3)->create();
        $posts->each(function (Post $post): void {
            Comment::factory()->count(2)->create(['post_id' => $post->getKey()]);
        });

        $loader = new VariantBatchLoader('comments', static fn (): Closure => static function ($query): void {
            $query->addSelect(['comments.id', 'comments.post_id']);
        });

        $this->sqlCounterReset();

        $deferreds = $posts->map(fn (Post $post) => $loader->load($post))->all();
        SyncPromiseQueue::run();

        // One base relation query for ALL parents combined:
        $this->assertSqlCount(1);

        foreach ($posts as $i => $post) {
            $result = null;
            $deferreds[$i]->then(function ($value) use (&$result): void {
                $result = $value;
            });
            SyncPromiseQueue::run();

            self::assertCount(2, $result);
            self::assertTrue($result->every(fn (Comment $c): bool => $c->post_id === $post->getKey()));
            // The user-visible parent was never touched:
            self::assertFalse($post->relationLoaded('comments'));
        }
    }

    public function testTwoLoadersSameRelationDifferentConstraintsAreIndependent(): void
    {
        /** @var Post $post */
        $post = Post::factory()->create();
        /** @var Comment $c1 */
        $c1 = Comment::factory()->create(['post_id' => $post->getKey()]);
        /** @var Comment $c2 */
        $c2 = Comment::factory()->create(['post_id' => $post->getKey()]);

        $loaderA = new VariantBatchLoader('comments', static fn (): Closure => static function ($query) use ($c1): void {
            $query->where('comments.id', $c1->getKey());
        });
        $loaderB = new VariantBatchLoader('comments', static fn (): Closure => static function ($query) use ($c2): void {
            $query->where('comments.id', $c2->getKey());
        });

        $resultA = $resultB = null;
        $loaderA->load($post)->then(function ($value) use (&$resultA): void {
            $resultA = $value;
        });
        $loaderB->load($post)->then(function ($value) use (&$resultB): void {
            $resultB = $value;
        });
        SyncPromiseQueue::run();

        self::assertSame([$c1->getKey()], $resultA->modelKeys());
        self::assertSame([$c2->getKey()], $resultB->modelKeys());
        self::assertFalse($post->relationLoaded('comments'));
    }

    /**
     * Spec §2.3, force-time constraints: the factory must not run at
     * loader creation nor at load() — only at the FIRST force — and must
     * run exactly once even across multiple waves.
     */
    public function testConstraintsFactoryIsEvaluatedOnceAtFirstForce(): void
    {
        /** @var Post $postA */
        $postA = Post::factory()->create();
        /** @var Post $postB */
        $postB = Post::factory()->create();
        Comment::factory()->create(['post_id' => $postA->getKey()]);
        Comment::factory()->create(['post_id' => $postB->getKey()]);

        $counter = new class {
            public int $calls = 0;
        };
        $loader = new VariantBatchLoader('comments', static function () use ($counter): Closure {
            $counter->calls++;

            return static function ($query): void {
            };
        });

        $loader->load($postA);
        self::assertSame(0, $counter->calls);

        SyncPromiseQueue::run();
        self::assertSame(1, $counter->calls);

        // A late wave reuses the already-built constraints:
        $loader->load($postB);
        SyncPromiseQueue::run();
        self::assertSame(1, $counter->calls);
    }

    /**
     * Spec §2.3, late-wave re-batching: parents collected after a prior
     * force are batch-loaded on the next force (one additional base query
     * per late wave); earlier results stay intact.
     */
    public function testLateParentsAreReBatchedOnNextForce(): void
    {
        /** @var Post $postA */
        $postA = Post::factory()->create();
        /** @var Post $postB */
        $postB = Post::factory()->create();
        /** @var Post $postC */
        $postC = Post::factory()->create();

        foreach ([$postA, $postB, $postC] as $post) {
            Comment::factory()->create(['post_id' => $post->getKey()]);
        }

        $loader = new VariantBatchLoader('comments', static fn (): Closure => static function ($query): void {
        });

        $first = null;
        $loader->load($postA)->then(function ($value) use (&$first): void {
            $first = $value;
        });

        $this->sqlCounterReset();
        SyncPromiseQueue::run();
        $this->assertSqlCount(1);
        self::assertSame([$postA->comments()->firstOrFail()->getKey()], $first->modelKeys());

        // Two LATE parents arrive after the first force — ONE additional
        // base query for both, never an undefined-result read.
        $late1 = $late2 = null;
        $loader->load($postB)->then(function ($value) use (&$late1): void {
            $late1 = $value;
        });
        $loader->load($postC)->then(function ($value) use (&$late2): void {
            $late2 = $value;
        });

        $this->sqlCounterReset();
        SyncPromiseQueue::run();
        $this->assertSqlCount(1);

        self::assertSame([$postB->comments()->firstOrFail()->getKey()], $late1->modelKeys());
        self::assertSame([$postC->comments()->firstOrFail()->getKey()], $late2->modelKeys());
        self::assertSame([$postA->comments()->firstOrFail()->getKey()], $first->modelKeys());
    }

    public function testDuplicateRowInstancesShareOneResult(): void
    {
        /** @var Post $post */
        $post = Post::factory()->create();
        Comment::factory()->create(['post_id' => $post->getKey()]);
        /** @var Post $again */
        $again = Post::query()->findOrFail($post->getKey());

        $loader = new VariantBatchLoader('comments', static fn (): Closure => static function ($query): void {
        });

        $r1 = $r2 = null;
        $loader->load($post)->then(function ($value) use (&$r1): void {
            $r1 = $value;
        });
        $loader->load($again)->then(function ($value) use (&$r2): void {
            $r2 = $value;
        });

        $this->sqlCounterReset();
        SyncPromiseQueue::run();
        $this->assertSqlCount(1);

        self::assertSame($r1, $r2);
    }
}
