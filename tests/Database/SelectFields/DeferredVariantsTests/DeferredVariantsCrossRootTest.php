<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use Rebing\GraphQL\Support\SelectFields\Deferred\DeferredVariantsMiddleware;
use Rebing\GraphQL\Support\SelectFields\Deferred\DeferredVariantsRegistry;
use Rebing\GraphQL\Support\SelectFields\Deferred\VariantAwareRelationResolver;
use Rebing\GraphQL\Tests\Database\SelectFields\AliasedRelationArgTests\PostType;
use Rebing\GraphQL\Tests\Database\SelectFields\AliasedRelationArgTests\UserType;
use Rebing\GraphQL\Tests\Support\Models\Post;
use Rebing\GraphQL\Tests\Support\Models\User;
use Rebing\GraphQL\Tests\Support\Traits\SqlAssertionTrait;

/**
 * Whole-branch review regression tests: cross-root interception (spec §2.2
 * observations), force-time loader constraints (spec §2.3), decorator
 * identity across scoped-instance flushes and the armed handshake for
 * executions that bypass the middleware.
 */
class DeferredVariantsCrossRootTest extends DeferredVariantsTestCase
{
    use SqlAssertionTrait;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('graphql.schemas.default', [
            'query' => [
                CaptureUsersQuery::class,
            ],
        ]);
        $app['config']->set('graphql.types', [
            PostType::class,
            UserType::class,
        ]);
    }

    /**
     * @return array{User,Post,Post}
     */
    private function seedUserWithFlaggedPosts(): array
    {
        $user = User::factory()->create();
        $flagged = Post::factory()->create(['flag' => true, 'user_id' => $user->id]);
        $unflagged = Post::factory()->create(['flag' => false, 'user_id' => $user->id]);

        return [$user, $flagged, $unflagged];
    }

    /**
     * CRITICAL 1 (spec §2.2, observations): a NON-conflicted position in
     * root branch `y` shares (type, field, args) with a variant registered
     * by root branch `x`. The resolver intercepts y's position with x's
     * spec; without the registry observing y's legacy subtree, the loader
     * under-selects (`body` silently null) — a regression vs 1.0.
     */
    public function testLegacyPositionCoincidingWithAnotherRootsVariantGetsItsSubselection(): void
    {
        [, $flagged, $unflagged] = $this->seedUserWithFlaggedPosts();

        $result = $this->httpGraphql('{
            x: users(select: true, with: true) {
                id
                a: posts(flag: true) { id }
                b: posts(flag: false) { id }
            }
            y: users(select: true, with: true) {
                id
                posts(flag: true) { id body }
            }
        }');

        $x = $result['data']['x'][0];
        self::assertSame([(string) $flagged->id], array_column($x['a'], 'id'));
        self::assertSame([(string) $unflagged->id], array_column($x['b'], 'id'));

        // y's non-conflicted position resolves with the UNION sub-selection:
        // its own `body` must survive the interception by x's variant spec.
        $y = $result['data']['y'][0];
        self::assertSame(
            [['id' => (string) $flagged->id, 'body' => $flagged->body]],
            $y['posts'],
        );

        self::assertTrue($this->app->make(DeferredVariantsRegistry::class)->isEmpty());
    }

    /**
     * CRITICAL 1, reversed root order: the legacy branch is processed
     * BEFORE any spec exists, so its observation is stored raw (hash-free)
     * and back-merged at register() time. The legacy branch itself resolves
     * synchronously before x registers (correct data either way) — the
     * observable effect of the back-merge is the variant base query
     * selecting the UNION sub-selection (`body` included).
     */
    public function testObservationStoredBeforeAnySpecIsBackMergedAtRegisterTime(): void
    {
        [, $flagged, $unflagged] = $this->seedUserWithFlaggedPosts();

        $this->sqlCounterReset();

        $result = $this->httpGraphql('{
            y: users(select: true, with: true) {
                id
                posts(flag: true) { id body }
            }
            x: users(select: true, with: true) {
                id
                a: posts(flag: true) { id }
                b: posts(flag: false) { id }
            }
        }');

        $y = $result['data']['y'][0];
        self::assertSame(
            [['id' => (string) $flagged->id, 'body' => $flagged->body]],
            $y['posts'],
        );
        $x = $result['data']['x'][0];
        self::assertSame([(string) $flagged->id], array_column($x['a'], 'id'));
        self::assertSame([(string) $unflagged->id], array_column($x['b'], 'id'));

        // Pin the back-merge at the SQL level: the flag=true variant base
        // query must carry y's `body` (union of the raw observation stored
        // before x registered its specs).
        $this->assertSqlQueries(<<<'SQL'
select "users"."id" from "users" order by "users"."id" asc;
select "posts"."id", "posts"."body", "posts"."user_id" from "posts" where "posts"."user_id" in (?) and posts.flag = ? order by "posts"."id" asc;
select "users"."id" from "users" order by "users"."id" asc;
select "posts"."id", "posts"."user_id", "posts"."body" from "posts" where "posts"."user_id" in (?) and posts.flag = ? order by "posts"."id" asc;
select "posts"."id", "posts"."user_id" from "posts" where "posts"."user_id" in (?) and posts.flag = ? order by "posts"."id" asc;
SQL);
    }

    /**
     * CRITICAL 2 (spec §2.3, force-time constraints): both roots are
     * conflicted on the same variants; y's register() deep-merges `body`
     * into the spec AFTER x's resolver hits already created the loader.
     * The constraint closure must therefore be built at first FORCE (all
     * roots registered by then under SyncPromiseAdapter), not at loader
     * creation — otherwise y under-selects.
     */
    public function testSpecMergeLandingAfterFirstResolverHitIsHonoredInTheSql(): void
    {
        [, $flagged, $unflagged] = $this->seedUserWithFlaggedPosts();

        $result = $this->httpGraphql('{
            x: users(select: true, with: true) {
                id
                a: posts(flag: true) { id }
                b: posts(flag: false) { id }
            }
            y: users(select: true, with: true) {
                id
                a: posts(flag: true) { id body }
                b: posts(flag: false) { id }
            }
        }');

        $x = $result['data']['x'][0];
        self::assertSame([(string) $flagged->id], array_column($x['a'], 'id'));
        self::assertSame([(string) $unflagged->id], array_column($x['b'], 'id'));

        // y's `body` was merged into the shared spec after x's branch had
        // already hit the resolver; force-time constraints must honor it.
        $y = $result['data']['y'][0];
        self::assertSame(
            [['id' => (string) $flagged->id, 'body' => $flagged->body]],
            $y['a'],
        );
        self::assertSame([(string) $unflagged->id], array_column($y['b'], 'id'));

        self::assertTrue($this->app->make(DeferredVariantsRegistry::class)->isEmpty());
    }

    /**
     * MINOR 6: aliased-with-args alongside unaliased-no-args — the
     * empty-args variant (`match(..., [])`). This is the exact shape
     * `AliasedRelationArgTest::testAliasedRelationAlongsideUnaliased`
     * documents as buggy for the ^10.0 matrix cell.
     */
    public function testAliasedWithArgsAlongsideUnaliasedNoArgsResolvesBothCorrectly(): void
    {
        [, $flagged, $unflagged] = $this->seedUserWithFlaggedPosts();

        $this->sqlCounterReset();

        $result = $this->httpGraphql('{ users(select: true, with: true) {
            id
            posts { id title }
            flaggedPosts: posts(flag: true) { id title }
        } }');

        // 1 users query + 1 base query per variant ({} and {flag: true}).
        $this->assertSqlCount(3);

        $userNode = $result['data']['users'][0];
        self::assertSame(
            [(string) $flagged->id, (string) $unflagged->id],
            array_column($userNode['posts'], 'id'),
        );
        self::assertSame($flagged->title, $userNode['posts'][0]['title']);
        self::assertSame(
            [['id' => (string) $flagged->id, 'title' => $flagged->title]],
            $userNode['flaggedPosts'],
        );
    }

    /**
     * IMPORTANT 4 (spec §2.2, decorator identity): long-lived non-Octane
     * runtimes (queue workers) flush scoped bindings but keep config. The
     * persisted decorator then holds the PREVIOUS execution's registry;
     * without the identity re-wrap every match misses and the relations
     * degrade to unconstrained lazy loads (wrong per-alias data).
     */
    public function testScopedInstanceFlushBetweenExecutionsRewrapsTheDecorator(): void
    {
        [, $flagged, $unflagged] = $this->seedUserWithFlaggedPosts();

        $query = '{ users(select: true, with: true) {
            id
            a: posts(flag: true) { id }
            b: posts(flag: false) { id }
        } }';

        $first = $this->httpGraphql($query);
        self::assertSame([(string) $flagged->id], array_column($first['data']['users'][0]['a'], 'id'));

        // Simulate worker request-boundary cleanup: scoped instances are
        // forgotten, config (and the decorator stored in it) survives.
        $this->app->forgetScopedInstances();

        $second = $this->httpGraphql($query);

        $userNode = $second['data']['users'][0];
        self::assertSame([(string) $flagged->id], array_column($userNode['a'], 'id'));
        self::assertSame([(string) $unflagged->id], array_column($userNode['b'], 'id'));

        // The decorator now holds the CURRENT scoped registry, and was
        // re-wrapped around the original inner resolver (never a decorator
        // inside a decorator).
        $resolver = config('graphql.defaultFieldResolver');
        self::assertInstanceOf(VariantAwareRelationResolver::class, $resolver);
        self::assertSame($this->app->make(DeferredVariantsRegistry::class), $resolver->registry());
        self::assertNotInstanceOf(VariantAwareRelationResolver::class, $resolver->inner());
    }

    /**
     * IMPORTANT 5 (spec §2.2, armed handshake): an execution that bypasses
     * the middleware (per-schema execution_middleware list set after
     * provider boot) must keep PURE legacy behavior — no specs registered
     * (nothing would ever consume them), no state leaking into a
     * subsequent normal execution.
     */
    public function testExecutionBypassingTheMiddlewareKeepsPureLegacyBehavior(): void
    {
        [, , $unflagged] = $this->seedUserWithFlaggedPosts();

        /** @var list<class-string> $withMiddleware */
        $withMiddleware = config('graphql.execution_middleware');
        $withoutMiddleware = array_values(array_filter(
            $withMiddleware,
            static fn (string $middleware): bool => DeferredVariantsMiddleware::class !== $middleware,
        ));
        self::assertNotSame($withMiddleware, $withoutMiddleware);

        // Per-schema lists REPLACE the global one entirely (see
        // \Rebing\GraphQL\GraphQL::executionMiddleware()).
        config()->set('graphql.schemas.default.execution_middleware', $withoutMiddleware);

        $query = '{ users(select: true, with: true) {
            id
            a: posts(flag: true) { id }
            b: posts(flag: false) { id }
        } }';

        $bypassed = $this->httpGraphql($query);

        // Pure legacy (pre-10.1) behavior: merged last-alias-wins args, one
        // shared eager load — both aliases return the flag=false post.
        $userNode = $bypassed['data']['users'][0];
        self::assertSame([(string) $unflagged->id], array_column($userNode['a'], 'id'));
        self::assertSame($userNode['a'], $userNode['b']);

        // Nothing was registered (the registry was never armed) …
        self::assertTrue($this->app->make(DeferredVariantsRegistry::class)->isEmpty());

        // … and a subsequent normal execution is unaffected.
        config()->set('graphql.schemas.default.execution_middleware', $withMiddleware);

        $normal = $this->httpGraphql($query);
        $userNode = $normal['data']['users'][0];
        self::assertCount(1, $userNode['a']);
        self::assertNotSame($userNode['a'], $userNode['b']);
        self::assertTrue($this->app->make(DeferredVariantsRegistry::class)->isEmpty());
    }
}
