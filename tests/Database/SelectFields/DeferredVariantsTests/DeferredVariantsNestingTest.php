<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use Rebing\GraphQL\Support\SelectFields\Deferred\DeferredVariantsRegistry;
use Rebing\GraphQL\Tests\Support\Models\Comment;
use Rebing\GraphQL\Tests\Support\Models\Like;
use Rebing\GraphQL\Tests\Support\Models\Post;
use Rebing\GraphQL\Tests\Support\Models\User;
use Rebing\GraphQL\Tests\Support\Traits\SqlAssertionTrait;

/**
 * Plan-2 Task 7: nesting, aliased ancestors, cross-root dedupe, depth>=2
 * batching and repeat-execution coverage for deferred variants. Every test
 * in this file is expected to PASS as-is (pinning behaviour already
 * delivered by Tasks 2-6) — see task-7-report.md for the RED/GREEN
 * breakdown per scenario.
 */
class DeferredVariantsNestingTest extends DeferredVariantsTestCase
{
    use SqlAssertionTrait;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('graphql.schemas.default', [
            'query' => [
                NestingPostsQuery::class,
                NestingUsersQuery::class,
            ],
        ]);
        $app['config']->set('graphql.types', [
            NestingLikeType::class,
            NestingCommentType::class,
            NestingPostType::class,
            NestingUserType::class,
        ]);
    }

    /**
     * Bullet: "Nesting" (part 1) — a plain nested relation (`likes`, no
     * conflict) inside EACH variant's own subtree must be eager-loaded
     * correctly as part of that variant's own base-query chain.
     */
    public function testNestedRelationLoadsWithinEachVariantSubtree(): void
    {
        /** @var Post $post */
        $post = Post::factory()->create();
        /** @var Comment $commentX */
        $commentX = Comment::factory()->create(['post_id' => $post->id]);
        /** @var Comment $commentY */
        $commentY = Comment::factory()->create(['post_id' => $post->id]);
        /** @var User $liker */
        $liker = User::factory()->create();
        $likeX = Like::factory()->make(['user_id' => $liker->id]);
        $likeX->likable()->associate($commentX);
        $likeX->save();
        $likeY = Like::factory()->make(['user_id' => $liker->id]);
        $likeY->likable()->associate($commentY);
        $likeY->save();

        $this->sqlCounterReset();

        $result = $this->httpGraphql(<<<GRAPHQL
        {
          nestingPosts {
            id
            a: comments(id: {$commentX->id}) { id likes { id } }
            b: comments(id: {$commentY->id}) { id likes { id } }
          }
        }
        GRAPHQL);

        // 1 root (posts) + 2 variant base queries (comments a/b) + 2 nested
        // 'likes' queries (one per variant's own subtree) = 5.
        $this->assertSqlCount(5);

        $node = $result['data']['nestingPosts'][0];
        self::assertSame([(string) $commentX->id], array_column($node['a'], 'id'));
        self::assertSame([(string) $likeX->id], array_column($node['a'][0]['likes'], 'id'));
        self::assertSame([(string) $commentY->id], array_column($node['b'], 'id'));
        self::assertSame([(string) $likeY->id], array_column($node['b'][0]['likes'], 'id'));
    }

    /**
     * Bullet: "Nesting" (part 2) — deferred-inside-deferred: variants on
     * `comments` (outer), and INSIDE one of those variants, two aliases of
     * `likes` with different `userId` args (inner conflict, only discovered
     * once the outer variant's own subtree is processed).
     */
    public function testDoublyNestedVariantConflictDeferredInsideDeferred(): void
    {
        /** @var Post $post */
        $post = Post::factory()->create();
        /** @var Comment $commentA */
        $commentA = Comment::factory()->create(['post_id' => $post->id]);
        /** @var Comment $commentB */
        $commentB = Comment::factory()->create(['post_id' => $post->id]);
        /** @var User $userP */
        $userP = User::factory()->create();
        /** @var User $userQ */
        $userQ = User::factory()->create();

        $likeP = Like::factory()->make(['user_id' => $userP->id]);
        $likeP->likable()->associate($commentA);
        $likeP->save();
        $likeQ = Like::factory()->make(['user_id' => $userQ->id]);
        $likeQ->likable()->associate($commentA);
        $likeQ->save();

        $this->sqlCounterReset();

        $result = $this->httpGraphql(<<<GRAPHQL
        {
          nestingPosts {
            a: comments(id: {$commentA->id}) {
              id
              p: likes(userId: {$userP->id}) { id }
              q: likes(userId: {$userQ->id}) { id }
            }
            b: comments(id: {$commentB->id}) { id }
          }
        }
        GRAPHQL);

        // 1 root (posts) + 2 outer variants (comments a/b) + 2 inner variants
        // (likes p/q, registered lazily when the 'a' variant's own subtree is
        // processed) = 5, regardless of the extra nesting level.
        $this->assertSqlCount(5);

        $node = $result['data']['nestingPosts'][0];
        self::assertSame([(string) $commentA->id], array_column($node['a'], 'id'));
        self::assertSame([(string) $likeP->id], array_column($node['a'][0]['p'], 'id'));
        self::assertSame([(string) $likeQ->id], array_column($node['a'][0]['q'], 'id'));
        self::assertSame([(string) $commentB->id], array_column($node['b'], 'id'));

        self::assertTrue($this->app->make(DeferredVariantsRegistry::class)->isEmpty());
    }

    /**
     * Bullet: "Aliased ancestors" — `x`/`y` alias the ROOT query field
     * itself, each with a single (non-aliased) `comments(id: …)` child. Since
     * each branch's `comments` occurrence has no LOCAL sibling conflict, the
     * enricher never attaches `argsVariants` to either — this is a pin that
     * two independent root-level resolutions with divergent nested args
     * never interact via the deferred-variants registry (which stays empty
     * throughout): the registry is keyed by (type, field, args), not by
     * which alias/ancestor path reached it, but here neither ever consults
     * it in the first place.
     */
    public function testAliasedAncestorsResolveIndependentlyPerBranch(): void
    {
        /** @var Post $post */
        $post = Post::factory()->create();
        /** @var Comment $commentA */
        $commentA = Comment::factory()->create(['post_id' => $post->id]);
        /** @var Comment $commentB */
        $commentB = Comment::factory()->create(['post_id' => $post->id]);

        $this->sqlCounterReset();

        $result = $this->httpGraphql(<<<GRAPHQL
        {
          x: nestingPosts { id comments(id: {$commentA->id}) { id } }
          y: nestingPosts { id comments(id: {$commentB->id}) { id } }
        }
        GRAPHQL);

        // 2 roots (x, y) + 2 independent LEGACY eager loads (no variants were
        // ever registered — neither branch has a local conflict) = 4.
        $this->assertSqlCount(4);

        self::assertSame([(string) $commentA->id], array_column($result['data']['x'][0]['comments'], 'id'));
        self::assertSame([(string) $commentB->id], array_column($result['data']['y'][0]['comments'], 'id'));

        self::assertTrue($this->app->make(DeferredVariantsRegistry::class)->isEmpty());
    }

    /**
     * Bullet: "Cross-root dedupe" — the literal brief query (single,
     * non-aliased `comments(id: A)` per root branch) can never register a
     * spec at all: `argsVariants` only forms when a field recurs with >=2
     * DISTINCT arg hashes at the SAME tree position (see
     * VariantsTreeEnricher::enrichLevel()'s `count($rawHashes) < 2` guard),
     * and a lone occurrence per branch never satisfies that. To exercise
     * genuine cross-root sharing, EACH branch here carries its own local
     * a/b conflict (so each independently registers hash(A) and hash(B));
     * because the registry key is (parentTypeName, fieldName, argsHash) —
     * not scoped to which root alias produced it — x's and y's
     * registrations for the SAME hash collapse into ONE spec (merged via
     * DeferredVariantsRegistry::register()), and the resulting loader's
     * $parents map is further deduped by row identity (ModelKey), since x
     * and y return the SAME underlying Post row here.
     */
    public function testCrossRootDedupeSharesOneLoaderPerVariant(): void
    {
        /** @var Post $post */
        $post = Post::factory()->create();
        /** @var Comment $commentA */
        $commentA = Comment::factory()->create(['post_id' => $post->id]);
        /** @var Comment $commentB */
        $commentB = Comment::factory()->create(['post_id' => $post->id]);

        $this->sqlCounterReset();

        $result = $this->httpGraphql(<<<GRAPHQL
        {
          x: nestingPosts {
            a: comments(id: {$commentA->id}) { id }
            b: comments(id: {$commentB->id}) { id }
          }
          y: nestingPosts {
            a: comments(id: {$commentA->id}) { id }
            b: comments(id: {$commentB->id}) { id }
          }
        }
        GRAPHQL);

        // 2 roots (x, y) + 2 SHARED variant queries (hash(A), hash(B), each
        // serving BOTH branches' Post row) = 4. Without cross-root dedupe
        // this would be 2 roots + 2 branches x 2 variants = 6.
        $this->assertSqlCount(4);

        foreach (['x', 'y'] as $alias) {
            $node = $result['data'][$alias][0];
            self::assertSame([(string) $commentA->id], array_column($node['a'], 'id'));
            self::assertSame([(string) $commentB->id], array_column($node['b'], 'id'));
        }

        self::assertTrue($this->app->make(DeferredVariantsRegistry::class)->isEmpty());
    }

    /**
     * Bullet: "Depth >= 2 batching" — variants on a SECOND-level relation
     * (`users { posts { a: comments(flag: true) b: comments(flag: false) } }`)
     * with MANY first-level parents (users, each with their own post) must
     * still resolve to exactly one base query per variant.
     */
    public function testDepthTwoBatchingExactlyOneQueryPerVariant(): void
    {
        foreach (range(1, 5) as $i) {
            /** @var User $user */
            $user = User::factory()->create();
            /** @var Post $post */
            $post = Post::factory()->create(['user_id' => $user->id]);
            Comment::factory()->create(['post_id' => $post->id, 'flag' => true]);
            Comment::factory()->create(['post_id' => $post->id, 'flag' => false]);
        }

        $this->sqlCounterReset();

        $result = $this->httpGraphql(<<<'GRAPHQL'
        {
          nestingUsers {
            id
            posts {
              id
              a: comments(flag: true) { id }
              b: comments(flag: false) { id }
            }
          }
        }
        GRAPHQL);

        // 1 (users) + 1 (posts, legacy eager load — no conflict on 'posts'
        // itself) + 2 (comments variants a/b, shared across ALL 5 posts) = 4,
        // independent of the 5 users/posts seeded.
        $this->assertSqlCount(4);

        self::assertCount(5, $result['data']['nestingUsers']);

        foreach ($result['data']['nestingUsers'] as $userNode) {
            self::assertCount(1, $userNode['posts']);
            $postNode = $userNode['posts'][0];
            self::assertCount(1, $postNode['a']);
            self::assertCount(1, $postNode['b']);
        }
    }

    /**
     * Spec §2.3, late-wave re-batching: the shared `comments` variant
     * loaders are FORCED early (branch x's Deferreds are queued during the
     * sync walk, before y's posts-variant Deferreds), yet branch y's
     * flag=false posts only reach them afterwards — parents collected
     * after a prior force must be re-batched on the next force (one
     * additional base query per late wave, never an undefined-result
     * read).
     */
    public function testLateWaveParentsAreReBatchedAfterFirstForce(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        /** @var Post $flaggedPost */
        $flaggedPost = Post::factory()->create(['user_id' => $user->id, 'flag' => true]);
        /** @var Post $unflaggedPost */
        $unflaggedPost = Post::factory()->create(['user_id' => $user->id, 'flag' => false]);

        $comments = [];

        foreach ([$flaggedPost, $unflaggedPost] as $post) {
            foreach ([true, false] as $flag) {
                $comments[$post->id][(int) $flag] =
                    Comment::factory()->create(['post_id' => $post->id, 'flag' => $flag]);
            }
        }

        $this->sqlCounterReset();

        $result = $this->httpGraphql(<<<'GRAPHQL'
        {
          x: nestingPosts(flag: true) {
            id
            a: comments(flag: true) { id }
            b: comments(flag: false) { id }
          }
          y: nestingUsers {
            id
            pa: posts(flag: true) { id a: comments(flag: true) { id } b: comments(flag: false) { id } }
            pb: posts(flag: false) { id a: comments(flag: true) { id } b: comments(flag: false) { id } }
          }
        }
        GRAPHQL);

        // 1 (x: flagged posts) + 1 (y: users) + 2 (comments a/b wave 1: x's
        // flagged post only) + 2 (posts pa/pb variants) + 2 (comments a/b
        // late wave: pb's unflagged post) = 8. pa's flagged post dedupes
        // against x's (same row) and issues no further comment queries.
        $this->assertSqlCount(8);

        $x = $result['data']['x'][0];
        self::assertSame((string) $flaggedPost->id, $x['id']);
        self::assertSame([(string) $comments[$flaggedPost->id][1]->id], array_column($x['a'], 'id'));
        self::assertSame([(string) $comments[$flaggedPost->id][0]->id], array_column($x['b'], 'id'));

        $y = $result['data']['y'][0];
        self::assertSame([(string) $flaggedPost->id], array_column($y['pa'], 'id'));
        self::assertSame([(string) $comments[$flaggedPost->id][1]->id], array_column($y['pa'][0]['a'], 'id'));
        self::assertSame([(string) $comments[$flaggedPost->id][0]->id], array_column($y['pa'][0]['b'], 'id'));

        // The late-wave parent: pb's unflagged post hit the already-forced
        // comment loaders and must still receive ITS OWN rows.
        self::assertSame([(string) $unflaggedPost->id], array_column($y['pb'], 'id'));
        self::assertSame([(string) $comments[$unflaggedPost->id][1]->id], array_column($y['pb'][0]['a'], 'id'));
        self::assertSame([(string) $comments[$unflaggedPost->id][0]->id], array_column($y['pb'][0]['b'], 'id'));

        self::assertTrue($this->app->make(DeferredVariantsRegistry::class)->isEmpty());
    }

    /**
     * Bullet: "Repeat executions" — running the SAME variant-bearing query
     * twice in one process (two httpGraphql() calls, i.e. two full
     * executions sharing the Octane-style long-lived container) behaves
     * identically both times: the registry is scoped per-execution and
     * flushed after each run.
     */
    public function testRepeatExecutionsBehaveIdentically(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        /** @var Post $post */
        $post = Post::factory()->create(['user_id' => $user->id]);
        Comment::factory()->create(['post_id' => $post->id, 'flag' => true]);
        Comment::factory()->create(['post_id' => $post->id, 'flag' => false]);

        $query = <<<'GRAPHQL'
        {
          nestingUsers {
            id
            posts {
              id
              a: comments(flag: true) { id }
              b: comments(flag: false) { id }
            }
          }
        }
        GRAPHQL;

        $this->sqlCounterReset();
        $first = $this->httpGraphql($query);
        $this->assertSqlCount(4);

        $this->sqlCounterReset();
        $second = $this->httpGraphql($query);
        $this->assertSqlCount(4);

        self::assertSame($first, $second);
        self::assertTrue($this->app->make(DeferredVariantsRegistry::class)->isEmpty());
    }
}
