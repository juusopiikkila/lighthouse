<?php

namespace Tests\Unit\Schema\Directives;

use Tests\DBTestCase;
use Tests\Utils\Models\Post;
use Tests\Utils\Models\User;
use Tests\Utils\Policies\UserPolicy;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;

class CanDirectiveDbTest extends DBTestCase
{
    /**
     * @test
     */
    public function itPassesIfModelInstanceIsNotNull(): void
    {
        $this->be(
            new User([
                'name' => UserPolicy::ADMIN,
            ])
        );

        $user = factory(User::class)->create(['name' => 'foo']);

        $this->schema = '
        type Query {
            user(id: ID @eq): User
                @can(ability: "view")
                @field(resolver: "'.$this->qualifyTestResolver('resolveUser').'")
        }
        
        type User {
            id: ID!
            name: String!
        }
        ';

        $this->graphQL("
        {
            user(id: {$user->getKey()}) {
                name
            }
        }
        ")->assertJson([
            'data' => [
                'user' => [
                    'name' => 'foo',
                ],
            ],
        ]);
    }

    /**
     * @test
     */
    public function itThrowsIfNotAuthorized(): void
    {
        $this->be(
            new User([
                'name' => UserPolicy::ADMIN,
            ])
        );

        $userB = User::create([
            'name' => 'foo',
        ]);

        $postB = factory(Post::class)->create([
            'user_id' => $userB->getKey(),
            'title' => 'Harry Potter and the Half-Blood Prince',
        ]);

        $this->schema = '
        type Query {
            post(id: ID @eq): Post
                @can(ability: "view")
                @field(resolver: "'.$this->qualifyTestResolver('resolvePost').'")
        }
        
        type Post {
            id: ID!
            title: String!
        }
        ';

        $this->graphQL("
        {
            post(id: {$postB->getKey()}) {
                title
            }
        }
        ")->assertErrorCategory(AuthorizationException::CATEGORY);
    }

    /**
     * @test
     */
    public function itCanHandleMultipleModels(): void
    {
        $user = User::create([
            'name' => UserPolicy::ADMIN,
        ]);
        $this->be($user);

        $postA = factory(Post::class)->create([
            'user_id' => $user->getKey(),
            'title' => 'Harry Potter and the Half-Blood Prince',
        ]);
        $postB = factory(Post::class)->create([
            'user_id' => $user->getKey(),
            'title' => 'Harry Potter and the Chamber of Secrets',
        ]);

        $this->schema = '
        type Query {
            deletePosts(id: [ID!]!): [Post!]!
                @delete
                @can(ability: "delete")
        }
        
        type Post {
            id: ID!
            title: String!
        }
        ';

        $this->graphQL("
        {
            deletePosts(id: [{$postA->getKey()}, {$postB->getKey()}]) {
                title
            }
        }
        ")->assertJson([
            'data' => [
                'deletePosts' => [
                    [
                        'title' => 'Harry Potter and the Half-Blood Prince',
                    ],
                    [
                        'title' => 'Harry Potter and the Chamber of Secrets',
                    ],
                ],
            ],
        ]);
    }

    public function resolveUser($root, array $args): ?User
    {
        return User::where('id', $args['id'])->first();
    }

    public function resolvePost($root, array $args): ?User
    {
        return Post::where('id', $args['id'])->first();
    }
}
