<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use Rebing\GraphQL\Tests\Database\SelectFields\AliasedRelationArgTests\PostType;
use Rebing\GraphQL\Tests\Database\SelectFields\AliasedRelationArgTests\UserType;
use Rebing\GraphQL\Tests\Support\Models\Post;
use Rebing\GraphQL\Tests\Support\Models\User;
use Rebing\GraphQL\Tests\Support\Traits\SqlAssertionTrait;

class DeferredVariantsTest extends DeferredVariantsTestCase
{
    use SqlAssertionTrait;

    protected function setUp(): void
    {
        parent::setUp();
        CaptureUsersQuery::$lastResult = null;
    }

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

    public function testAliasedRelationWithDifferentArgsResolvesEachVariantCorrectly(): void
    {
        [, $flagged, $unflagged] = $this->seedUserWithFlaggedPosts();

        $this->sqlCounterReset();

        $result = $this->httpGraphql('{ users(select: true, with: true) {
            id
            flaggedPosts: posts(flag: true) { id }
            unflaggedPosts: posts(flag: false) { id }
        } }');

        // 1 users query + 1 base query per variant = 3. This also proves —
        // through the FULL executor, not a hand-driven promise queue — that
        // all parents are collected before the first Deferred resolves.
        $this->assertSqlCount(3);

        $userNode = $result['data']['users'][0];
        // The #604 fix: each alias carries ITS OWN argument's data (the
        // UserType 'query' closure received the variant args, not merged).
        self::assertSame([(string) $flagged->id], array_column($userNode['flaggedPosts'], 'id'));
        self::assertSame([(string) $unflagged->id], array_column($userNode['unflaggedPosts'], 'id'));
    }

    public function testSameArgsAliasesCollapseToLegacySingleLoad(): void
    {
        [, $flagged] = $this->seedUserWithFlaggedPosts();

        $this->sqlCounterReset();

        $result = $this->httpGraphql('{ users(select: true, with: true) {
            id
            a: posts(flag: true) { id }
            b: posts(flag: true) { id }
        } }');

        // No variants emitted upstream (same args) → legacy $with → 2 queries.
        $this->assertSqlCount(2);

        $userNode = $result['data']['users'][0];
        self::assertSame([(string) $flagged->id], array_column($userNode['a'], 'id'));
        self::assertSame($userNode['a'], $userNode['b']);
    }

    public function testNoNPlusOne(): void
    {
        foreach (range(1, 5) as $i) {
            $this->seedUserWithFlaggedPosts();
        }

        $this->sqlCounterReset();

        $result = $this->httpGraphql('{ users(select: true, with: true) {
            id
            flaggedPosts: posts(flag: true) { id }
            unflaggedPosts: posts(flag: false) { id }
        } }');

        // Still 1 + 2 base queries — independent of parent count.
        $this->assertSqlCount(3);

        self::assertCount(5, $result['data']['users']);

        foreach ($result['data']['users'] as $userNode) {
            self::assertCount(1, $userNode['flaggedPosts']);
            self::assertCount(1, $userNode['unflaggedPosts']);
        }
    }

    public function testParentsRelationsUntouchedAfterExecution(): void
    {
        $this->seedUserWithFlaggedPosts();

        $this->httpGraphql('{ users(select: true, with: true) {
            id
            flaggedPosts: posts(flag: true) { id }
            unflaggedPosts: posts(flag: false) { id }
        } }');

        self::assertNotNull(CaptureUsersQuery::$lastResult);

        // Clone-based loading: the models handed to the executor were never
        // mutated — no 'posts' relation was stuffed onto them.
        foreach (CaptureUsersQuery::$lastResult as $user) {
            self::assertFalse($user->relationLoaded('posts'));
        }
    }

    public function testVariantBaseQueriesSelectTheChildForeignKey(): void
    {
        $this->seedUserWithFlaggedPosts();

        $this->sqlCounterReset();

        $this->httpGraphql('{ users(select: true, with: true) {
            id
            flaggedPosts: posts(flag: true) { id }
            unflaggedPosts: posts(flag: false) { id }
        } }');

        // Result matching already proves FK handling end-to-end; this pins
        // it at the SQL level: every variant base query must select the
        // child FK (posts.user_id) used to match results back to parents.
        // Use the trait's recorded queries; mirror the exact-SQL style of
        // AliasedRelationArgTest::assertSqlQueries — copy the exact expected
        // SQL from the first failure output and commit the pinned form.
        $this->assertSqlQueries(<<<'SQL'
select "users"."id" from "users" order by "users"."id" asc;
select "posts"."id", "posts"."user_id" from "posts" where "posts"."user_id" in (?) and posts.flag = ? order by "posts"."id" asc;
select "posts"."id", "posts"."user_id" from "posts" where "posts"."user_id" in (?) and posts.flag = ? order by "posts"."id" asc;
SQL);
    }
}
