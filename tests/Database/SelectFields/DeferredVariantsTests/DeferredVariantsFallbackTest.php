<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use Illuminate\Support\Facades\Log;
use Rebing\GraphQL\Support\SelectFields\Deferred\DeferredVariantsRegistry;
use Rebing\GraphQL\Tests\Database\SelectFields\AliasedRelationArgTests\PostType as AliasedPostType;
use Rebing\GraphQL\Tests\Database\SelectFields\AliasedRelationArgTests\UsersQuery;
use Rebing\GraphQL\Tests\Database\SelectFields\AliasedRelationArgTests\UserType as AliasedUserType;
use Rebing\GraphQL\Tests\Support\Models\Comment;
use Rebing\GraphQL\Tests\Support\Models\Like;
use Rebing\GraphQL\Tests\Support\Models\Post;
use Rebing\GraphQL\Tests\Support\Models\User;
use Rebing\GraphQL\Tests\Support\Traits\SqlAssertionTrait;

/**
 * Plan-2 Task 7: fallback (unsupported relation kinds), custom-resolver
 * silence, flag-off, exception unwind and interplay coverage for deferred
 * variants. See task-7-report.md for the RED/GREEN breakdown per scenario
 * (the two production fixes this file drives are documented there).
 */
class DeferredVariantsFallbackTest extends DeferredVariantsTestCase
{
    use SqlAssertionTrait;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('graphql.schemas.default', [
            'query' => [
                FallbackPostsQuery::class,
                FallbackWrapPostsQuery::class,
                FallbackLikesQuery::class,
                UsersQuery::class,
                ThrowingUsersQuery::class,
            ],
        ]);
        $app['config']->set('graphql.types', [
            FallbackCommentType::class,
            FallbackPostType::class,
            FallbackWrapCommentType::class,
            FallbackWrapPostType::class,
            FallbackLikableInterfaceType::class,
            FallbackMorphPostType::class,
            FallbackMorphCommentType::class,
            FallbackLikeType::class,
            AliasedPostType::class,
            AliasedUserType::class,
        ]);
    }

    private function registry(): DeferredVariantsRegistry
    {
        return $this->app->make(DeferredVariantsRegistry::class);
    }

    // -- Fallback: paginated/wrapped relation field ------------------------

    public function testWrapTypeFallbackWarnsAndUsesLegacyMergedBehavior(): void
    {
        config(['graphql.select_fields.strict' => false]);

        /** @var Post $post */
        $post = Post::factory()->create();
        /** @var Comment $commentX */
        $commentX = Comment::factory()->create(['post_id' => $post->id]);
        /** @var Comment $commentY */
        $commentY = Comment::factory()->create(['post_id' => $post->id]);

        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'unsupported') &&
                'FallbackWrapPost' === $context['parentType'] &&
                'comments' === $context['field'];
        });

        $result = $this->httpGraphql(<<<GRAPHQL
        {
          fallbackWrapPosts {
            a: comments(id: {$commentX->id}) { data { id } }
            b: comments(id: {$commentY->id}) { data { id } }
          }
        }
        GRAPHQL);

        $node = $result['data']['fallbackWrapPosts'][0];
        // Current 1.0 behaviour: both aliases identical, merged (last-alias-wins) args.
        self::assertSame($node['a'], $node['b']);
        self::assertSame([(string) $commentY->id], array_column($node['b']['data'], 'id'));

        self::assertTrue($this->registry()->isEmpty());
    }

    public function testWrapTypeFallbackThrowsInStrictMode(): void
    {
        config(['graphql.select_fields.strict' => true]);

        /** @var Post $post */
        $post = Post::factory()->create();
        /** @var Comment $commentX */
        $commentX = Comment::factory()->create(['post_id' => $post->id]);
        /** @var Comment $commentY */
        $commentY = Comment::factory()->create(['post_id' => $post->id]);

        Log::shouldReceive('warning')->once();
        // The DeferredVariantsException that strict mode throws propagates
        // out of the resolver as an "unsafe" exception; Laravel's exception
        // handler reports (Log::error()s) it as part of normal HTTP error
        // handling — allow that call too, it's not what this test pins.
        Log::shouldReceive('error')->andReturnNull();

        $result = $this->httpGraphql(<<<GRAPHQL
        {
          fallbackWrapPosts {
            a: comments(id: {$commentX->id}) { data { id } }
            b: comments(id: {$commentY->id}) { data { id } }
          }
        }
        GRAPHQL, ['expectErrors' => true]);

        self::assertStringContainsString(
            'Unsupported deferred variant on FallbackWrapPost.comments',
            $result['errors'][0]['extensions']['debugMessage'] ?? '',
        );
        self::assertTrue($this->registry()->isEmpty());
    }

    // -- Fallback: MorphTo relation -----------------------------------------

    public function testMorphToFallbackWarnsAndUsesLegacyMergedBehavior(): void
    {
        config(['graphql.select_fields.strict' => false]);

        /** @var User $user */
        $user = User::factory()->create();
        /** @var Post $post */
        $post = Post::factory()->create(['user_id' => $user->id]);
        $like = Like::factory()->make(['user_id' => $user->id]);
        $like->likable()->associate($post);
        $like->save();

        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'unsupported') &&
                'FallbackLike' === $context['parentType'] &&
                'likable' === $context['field'];
        });

        $result = $this->httpGraphql(<<<'GRAPHQL'
        {
          fallbackLikes {
            a: likable(flag: true) { id }
            b: likable(flag: false) { id }
          }
        }
        GRAPHQL);

        $node = $result['data']['fallbackLikes'][0];
        self::assertSame($node['a'], $node['b']);
        self::assertSame((string) $post->id, $node['a']['id']);

        self::assertTrue($this->registry()->isEmpty());
    }

    public function testMorphToFallbackThrowsInStrictMode(): void
    {
        config(['graphql.select_fields.strict' => true]);

        /** @var User $user */
        $user = User::factory()->create();
        /** @var Post $post */
        $post = Post::factory()->create(['user_id' => $user->id]);
        $like = Like::factory()->make(['user_id' => $user->id]);
        $like->likable()->associate($post);
        $like->save();

        Log::shouldReceive('warning')->once();
        Log::shouldReceive('error')->andReturnNull();

        $result = $this->httpGraphql(<<<'GRAPHQL'
        {
          fallbackLikes {
            a: likable(flag: true) { id }
            b: likable(flag: false) { id }
          }
        }
        GRAPHQL, ['expectErrors' => true]);

        self::assertStringContainsString(
            'Unsupported deferred variant on FallbackLike.likable',
            $result['errors'][0]['extensions']['debugMessage'] ?? '',
        );
        self::assertTrue($this->registry()->isEmpty());
    }

    // -- Custom resolver stays silent ---------------------------------------

    public function testCustomResolverStaysSilent(): void
    {
        /** @var Post $post */
        $post = Post::factory()->create();
        /** @var Comment $commentX */
        $commentX = Comment::factory()->create(['post_id' => $post->id]);
        /** @var Comment $commentY */
        $commentY = Comment::factory()->create(['post_id' => $post->id]);

        Log::shouldReceive('warning')->never();

        $result = $this->httpGraphql(<<<GRAPHQL
        {
          fallbackPosts {
            a: customComments(id: {$commentX->id}) { id }
            b: customComments(id: {$commentY->id}) { id }
          }
        }
        GRAPHQL);

        $node = $result['data']['fallbackPosts'][0];
        // Custom resolvers get per-node args from the executor — correct
        // PER-ALIAS data, unlike the merged-args legacy fallback.
        self::assertSame([(string) $commentX->id], array_column($node['a'], 'id'));
        self::assertSame([(string) $commentY->id], array_column($node['b'], 'id'));

        self::assertTrue($this->registry()->isEmpty());
    }

    // -- Flag off -------------------------------------------------------------

    public function testFlagOffMatchesLegacyBehaviorExactly(): void
    {
        config(['graphql.select_fields.deferred_variants' => false]);

        /** @var User $user */
        $user = User::factory()->create();
        Post::factory()->create(['flag' => true, 'user_id' => $user->id]);
        /** @var Post $unflagged */
        $unflagged = Post::factory()->create(['flag' => false, 'user_id' => $user->id]);

        Log::shouldReceive('warning')->never();

        $this->sqlCounterReset();

        $result = $this->httpGraphql('{ users(select: true, with: true) {
            id
            flaggedPosts: posts(flag: true) { id }
            unflaggedPosts: posts(flag: false) { id }
        } }');

        // Legacy query count: 1 (users) + 1 (posts, single merged eager load).
        $this->assertSqlCount(2);

        $node = $result['data']['users'][0];
        self::assertSame($node['flaggedPosts'], $node['unflaggedPosts']);
        // Last-alias-wins: 'unflaggedPosts' (flag: false) is declared last.
        self::assertSame([(string) $unflagged->id], array_column($node['flaggedPosts'], 'id'));

        self::assertTrue($this->registry()->isEmpty());
    }

    // -- Exception unwind -----------------------------------------------------

    public function testExceptionUnwindSurfacesOriginalErrorAndEmptiesRegistry(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        Post::factory()->create(['flag' => true, 'user_id' => $user->id]);
        Post::factory()->create(['flag' => false, 'user_id' => $user->id]);

        $spy = new class extends DeferredVariantsRegistry {
            /** @var list<bool> */
            public array $flushCalls = [];

            public function flush(bool $successful): void
            {
                $this->flushCalls[] = $successful;
                parent::flush($successful);
            }
        };
        $this->app->instance(DeferredVariantsRegistry::class, $spy);

        $result = $this->httpGraphql('{ throwingUsers(select: true, with: true) {
            id
            flaggedPosts: posts(flag: true) { id }
            unflaggedPosts: posts(flag: false) { id }
        } }', ['expectErrors' => true]);

        $debugMessage = $result['errors'][0]['extensions']['debugMessage'] ?? '';
        self::assertStringContainsString('boom: resolver threw mid-execution', $debugMessage);
        self::assertStringNotContainsString('DeferredVariantsException', $debugMessage);
        self::assertStringNotContainsString('DeferredVariantsException', $result['errors'][0]['message']);

        self::assertSame([false], $spy->flushCalls);
        self::assertTrue($spy->isEmpty());
    }

    // -- Interplay (a): 'alias'-config relation stays silent legacy ----------

    public function testInterplayAliasConfigRelationStaysSilentLegacy(): void
    {
        /** @var Post $post */
        $post = Post::factory()->create();
        /** @var Comment $commentX */
        $commentX = Comment::factory()->create(['post_id' => $post->id]);
        /** @var Comment $commentY */
        $commentY = Comment::factory()->create(['post_id' => $post->id]);

        Log::shouldReceive('warning')->never();

        $result = $this->httpGraphql(<<<GRAPHQL
        {
          fallbackPosts {
            a: taggedComments(id: {$commentX->id}) { id }
            b: taggedComments(id: {$commentY->id}) { id }
          }
        }
        GRAPHQL);

        $node = $result['data']['fallbackPosts'][0];
        // Documented limitation (README note lands in Task 8): graphql-laravel
        // injects a 'resolve' closure for array-config fields carrying
        // 'alias' (Type::getFieldResolver()), so `hasCustomResolver()` treats
        // this the same as a genuine custom resolver — no registry activity,
        // no warning, silent legacy merged (last-alias-wins) behaviour.
        self::assertSame($node['a'], $node['b']);
        self::assertSame([(string) $commentY->id], array_column($node['b'], 'id'));

        self::assertTrue($this->registry()->isEmpty());
    }

    // -- Interplay (b): 'always' columns propagate into each variant subtree -

    public function testInterplayAlwaysColumnsPropagateIntoEachVariantSubtree(): void
    {
        /** @var Post $post */
        $post = Post::factory()->create();
        /** @var Comment $commentX */
        $commentX = Comment::factory()->create(['post_id' => $post->id, 'flag' => true]);
        /** @var Comment $commentY */
        $commentY = Comment::factory()->create(['post_id' => $post->id, 'flag' => false]);

        $this->sqlCounterReset();

        $result = $this->httpGraphql(<<<GRAPHQL
        {
          fallbackPosts {
            a: comments(id: {$commentX->id}) { id }
            b: comments(id: {$commentY->id}) { id }
          }
        }
        GRAPHQL);

        $commentsQueries = array_values(array_filter(
            $this->sqlQueryEvents,
            static fn ($event): bool => str_contains($event->sql, 'from "comments"'),
        ));

        // One base query per variant, EACH selecting the 'always' column
        // ('flag') even though only 'id' was requested.
        self::assertCount(2, $commentsQueries);

        foreach ($commentsQueries as $event) {
            self::assertStringContainsString('"flag"', $event->sql);
        }

        $node = $result['data']['fallbackPosts'][0];
        self::assertSame([(string) $commentX->id], array_column($node['a'], 'id'));
        self::assertSame([(string) $commentY->id], array_column($node['b'], 'id'));

        $this->sqlCounterReset();
    }

    // -- Interplay (c): 'selectable' => false sibling unaffected -------------

    public function testInterplaySelectableFalseSiblingUnaffected(): void
    {
        /** @var Post $post */
        $post = Post::factory()->create(['body' => 'hidden body']);
        /** @var Comment $commentX */
        $commentX = Comment::factory()->create(['post_id' => $post->id]);
        /** @var Comment $commentY */
        $commentY = Comment::factory()->create(['post_id' => $post->id]);

        $result = $this->httpGraphql(<<<GRAPHQL
        {
          fallbackPosts {
            secret
            a: comments(id: {$commentX->id}) { id }
            b: comments(id: {$commentY->id}) { id }
          }
        }
        GRAPHQL);

        $node = $result['data']['fallbackPosts'][0];
        // 'body' was never selected (selectable => false) — resolves to null.
        self::assertNull($node['secret']);
        self::assertSame([(string) $commentX->id], array_column($node['a'], 'id'));
        self::assertSame([(string) $commentY->id], array_column($node['b'], 'id'));
    }

    // -- Interplay (d): privacy contract -------------------------------------

    public function testInterplayPrivacyCarryingFieldWarnsAndFallsBackLegacy(): void
    {
        config(['graphql.select_fields.strict' => false]);

        /** @var Post $post */
        $post = Post::factory()->create();
        /** @var Comment $commentX */
        $commentX = Comment::factory()->create(['post_id' => $post->id]);
        /** @var Comment $commentY */
        $commentY = Comment::factory()->create(['post_id' => $post->id]);

        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'unsupported') &&
                'FallbackPost' === $context['parentType'] &&
                'privateCommentsAllowed' === $context['field'];
        });

        $result = $this->httpGraphql(<<<GRAPHQL
        {
          fallbackPosts {
            a: privateCommentsAllowed(id: {$commentX->id}) { id }
            b: privateCommentsAllowed(id: {$commentY->id}) { id }
          }
        }
        GRAPHQL);

        $node = $result['data']['fallbackPosts'][0];
        self::assertSame($node['a'], $node['b']);
        self::assertSame([(string) $commentY->id], array_column($node['b'], 'id'));

        self::assertTrue($this->registry()->isEmpty());
    }

    public function testInterplayPrivacyCarryingFieldThrowsInStrictMode(): void
    {
        config(['graphql.select_fields.strict' => true]);

        /** @var Post $post */
        $post = Post::factory()->create();
        /** @var Comment $commentX */
        $commentX = Comment::factory()->create(['post_id' => $post->id]);
        /** @var Comment $commentY */
        $commentY = Comment::factory()->create(['post_id' => $post->id]);

        Log::shouldReceive('warning')->once();
        Log::shouldReceive('error')->andReturnNull();

        $result = $this->httpGraphql(<<<GRAPHQL
        {
          fallbackPosts {
            a: privateCommentsAllowed(id: {$commentX->id}) { id }
            b: privateCommentsAllowed(id: {$commentY->id}) { id }
          }
        }
        GRAPHQL, ['expectErrors' => true]);

        self::assertStringContainsString(
            'Unsupported deferred variant on FallbackPost.privateCommentsAllowed',
            $result['errors'][0]['extensions']['debugMessage'] ?? '',
        );
        self::assertTrue($this->registry()->isEmpty());
    }

    public function testInterplayPrivacyDeniedFieldResolvesNullEvenAsFallback(): void
    {
        config(['graphql.select_fields.strict' => false]);

        /** @var Post $post */
        $post = Post::factory()->create();
        /** @var Comment $commentX */
        $commentX = Comment::factory()->create(['post_id' => $post->id]);
        /** @var Comment $commentY */
        $commentY = Comment::factory()->create(['post_id' => $post->id]);

        Log::shouldReceive('warning')->once();

        $result = $this->httpGraphql(<<<GRAPHQL
        {
          fallbackPosts {
            a: privateCommentsDenied(id: {$commentX->id}) { id }
            b: privateCommentsDenied(id: {$commentY->id}) { id }
          }
        }
        GRAPHQL);

        $node = $result['data']['fallbackPosts'][0];
        // 1.0 privacy semantics: a denied field resolves to null outright.
        self::assertNull($node['a']);
        self::assertNull($node['b']);

        self::assertTrue($this->registry()->isEmpty());
    }
}
