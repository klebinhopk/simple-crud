<?php
namespace SimpleCrud\Tests;

use SimpleCrud\Engine\SchemeInterface;
use SimpleCrud\SimpleCrud;
use SimpleCrud\Table;

class RelationsTest extends AbstractTestCase
{
    private function createDatabase()
    {
        return $this->createSqliteDatabase([
            <<<'EOT'
CREATE TABLE "post" (
    `id`          INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE,
    `title`       TEXT
);
EOT
            ,
            <<<'EOT'
CREATE TABLE "category" (
    `id`          INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE,
    `name`        TEXT,
    `category_id` INTEGER
);
EOT
            ,
            <<<'EOT'
CREATE TABLE "comment" (
    `id`          INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE,
    `text`        TEXT,
    `post_id`     INTEGER
);
EOT
            ,
            <<<'EOT'
CREATE TABLE "category_post" (
    `id`          INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE,
    `category_id` INTEGER NOT NULL,
    `post_id`     INTEGER NOT NULL
);
EOT
        ]);
    }

    public function testCreation(): SimpleCrud
    {
        $db = $this->createDatabase();

        $db->post[] = ['title' => 'First post'];
        $db->category[] = ['name' => 'First category'];
        $db->comment[] = ['text' => 'Comment', 'post_id' => 1];
        $db->category_post[] = ['category_id' => 1, 'post_id' => 1];

        $this->assertEquals(1, $db->post->count()->run());
        $this->assertEquals(1, $db->category->count()->run());
        $this->assertEquals(1, $db->comment->count()->run());
        $this->assertEquals(1, $db->category_post->count()->run());

        return $db;
    }

    /**
     * @depends testCreation
     */
    public function testHasOneQuery(SimpleCrud $db)
    {
        $query = $db->comment->select()
            ->relatedWith($db->post[1])
            ->compile();

        $this->assertEquals(
            SchemeInterface::HAS_ONE,
            $db->getScheme()->getRelation($db->comment, $db->post)
        );

        $this->assertEquals([1], $query->params());
        $this->assertEquals(
            'SELECT "comment"."id", "comment"."text", "comment"."post_id" FROM "comment" WHERE "comment"."post_id" = ?',
            $query->sql()
        );

        $query = $db->comment->select()
            ->relatedWith($db->post)
            ->compile();

        $this->assertEmpty($query->params());
        $this->assertEquals(
            'SELECT "comment"."id", "comment"."text", "comment"."post_id" FROM "comment" WHERE "comment"."post_id" IS NOT NULL',
            $query->sql()
        );
    }

    /**
     * @depends testCreation
     */
    public function testSelfRelateQuery(SimpleCrud $db)
    {
        $query = $db->category->select()
            ->relatedWith($db->category[1])
            ->compile();

        $this->assertEquals(
            SchemeInterface::HAS_ONE,
            $db->getScheme()->getRelation($db->category, $db->category)
        );

        $this->assertEquals([1], $query->params());
        $this->assertEquals(
            'SELECT "category"."id", "category"."name", "category"."category_id" FROM "category" WHERE "category"."category_id" = ?',
            $query->sql()
        );

        $query = $db->category->select()
            ->relatedWith($db->category)
            ->compile();

        $this->assertEmpty($query->params());
        $this->assertEquals(
            'SELECT "category"."id", "category"."name", "category"."category_id" FROM "category" WHERE "category"."category_id" IS NOT NULL',
            $query->sql()
        );
    }

    /**
     * @depends testCreation
     */
    public function testHasManyQuery(SimpleCrud $db)
    {
        $query = $db->post->select()
            ->relatedWith($db->comment[1])
            ->compile();

        $this->assertEquals(
            SchemeInterface::HAS_MANY,
            $db->getScheme()->getRelation($db->post, $db->comment)
        );

        $this->assertEquals([1], $query->params());
        $this->assertEquals(
            'SELECT "post"."id", "post"."title" FROM "post" LEFT JOIN "comment" ON "comment"."post_id" = "post"."id" WHERE "comment"."id" = ?',
            $query->sql()
        );

        $query = $db->post->select()
            ->relatedWith($db->comment)
            ->compile();

        $this->assertEmpty($query->params());
        $this->assertEquals(
            'SELECT "post"."id", "post"."title" FROM "post" LEFT JOIN "comment" ON "comment"."post_id" = "post"."id" WHERE "comment"."post_id" IS NOT NULL',
            $query->sql()
        );
    }

    /**
     * @depends testCreation
     */
    public function testHasManyToManyQuery(SimpleCrud $db)
    {
        $query = $db->category->select()
            ->relatedWith($db->post[1])
            ->compile();

        $this->assertEquals(
            SchemeInterface::HAS_MANY_TO_MANY,
            $db->getScheme()->getRelation($db->category, $db->post)
        );

        $this->assertEquals([1], $query->params());
        $this->assertEquals(
            'SELECT "category"."id", "category"."name", "category"."category_id", "category_post"."post_id" FROM "category" LEFT JOIN "category_post" ON "category_post"."category_id" = "category"."id" WHERE "category_post"."post_id" = ?',
            $query->sql()
        );

        $query = $db->category->select()
            ->relatedWith($db->post)
            ->compile();

        $this->assertEmpty($query->params());
        $this->assertEquals(
            'SELECT "category"."id", "category"."name", "category"."category_id" FROM "category" LEFT JOIN "category_post" ON "category_post"."category_id" = "category"."id" WHERE "category_post"."post_id" IS NOT NULL',
            $query->sql()
        );
    }

    public function testRelateOne()
    {
        $db = $this->createDatabase();

        $post = $db->post->create(['title' => 'First post'])->save();
        $comment = $db->comment->create(['text' => 'Comment'])->save();

        //Relate
        $comment->relate($post);

        $this->assertEquals($post->id, $comment->post_id);

        $result = $db->post
            ->select()
            ->one()
            ->relatedWith($comment)
            ->run();

        $this->assertSame($post, $result);

        //Unrelate
        $comment->unrelate($post);

        $this->assertNull($comment->post_id);

        $result = $db->post
            ->select()
            ->one()
            ->relatedWith($comment)
            ->run();

        $this->assertNull($result);

        //Unrelate all
        $comment->relate($post);

        $this->assertEquals($post->id, $comment->post_id);

        $comment->unrelateAll($db->post);

        $this->assertNull($comment->post_id);

        $result = $db->post
            ->select()
            ->one()
            ->relatedWith($comment)
            ->run();

        $this->assertNull($result);
    }

    public function testRelateMany()
    {
        $db = $this->createDatabase();

        $post = $db->post->create(['title' => 'First post'])->save();
        $comment = $db->comment->create(['text' => 'Comment'])->save();

        //Relate
        $post->relate($comment);

        $this->assertEquals($post->id, $comment->post_id);

        $result = $db->comment
            ->select()
            ->relatedWith($post)
            ->run();

        $this->assertCount(1, $result);
        $this->assertSame($comment, $result[1]);

        //Unrelate
        $post->unrelate($comment);

        $this->assertNull($comment->post_id);

        $result = $db->comment
            ->select()
            ->relatedWith($post)
            ->run();

        $this->assertCount(0, $result);

        //Unrelate all
        $comment2 = $db->comment->create(['text' => 'Other comment'])->save();
        $post->relate($comment, $comment2);

        $result = $db->comment
            ->select()
            ->relatedWith($post)
            ->run();

        $this->assertCount(2, $result);

        $post->unrelateAll($db->comment);

        $result = $db->comment
            ->select()
            ->relatedWith($post)
            ->run();

        $this->assertCount(0, $result);
    }

    public function testRelateManyToMany()
    {
        $db = $this->createDatabase();

        $post = $db->post->create(['title' => 'First post'])->save();
        $category = $db->category->create(['name' => 'First category'])->save();

        //Relate
        $post->relate($category);

        $category_post = $db->category_post[1];

        $this->assertNotNull($category_post);
        $this->assertEquals($category_post->post_id, $post->id);
        $this->assertEquals($category_post->category_id, $category->id);

        $result = $db->category
            ->select()
            ->relatedWith($post)
            ->run();

        $this->assertCount(1, $result);
        $this->assertSame($category, $result[1]);

        //Unrelate
        $db->category_post->clearCache();

        $post->unrelate($category);
        $this->assertNull($db->category_post[1]);

        $result = $db->category
            ->select()
            ->relatedWith($post)
            ->run();

        $this->assertCount(0, $result);

        //Unrelate all
        $category2 = $db->category->create(['name' => 'Second category'])->save();
        $post->relate($category, $category2);

        $result = $db->category
            ->select()
            ->relatedWith($post)
            ->run();

        $this->assertCount(2, $result);

        $post->unrelateAll($db->category);

        $result = $db->category
            ->select()
            ->relatedWith($post)
            ->run();

        $this->assertCount(0, $result);
    }

    public function _testDirectRelatedData()
    {
        $db = $this->db;

        $post = $db->post->create([
            'title' => 'first',
        ])->save();

        $this->assertInstanceOf('SimpleCrud\\RowCollection', $post->category);
        $this->assertInstanceOf('SimpleCrud\\RowCollection', $post->comment);

        $comment = $db->comment->create(['text' => 'Hello world']);
        $comment->relate($post);

        $this->assertSame($post, $comment->post);

        $comment2 = $db->comment->create(['text' => 'Hello world 2']);
        $comment2->relate($post);

        $comments = $post->comment;

        $this->assertCount(2, $post->comment);

        $comment2->unrelate($post);

        $this->assertCount(1, $post->comment);

        //Left join
        $comments = $db->comment->select()
            ->leftJoin('post')
            ->run();

        $this->assertCount(2, $comments);

        $json = json_encode([
            [
                'id' => 1,
                'text' => 'Hello world',
                'post_id' => 1,
                'post' => [
                    'id' => 1,
                    'title' => 'first',
                    'category' => [],
                    'comment' => null,
                ],
            ], [
                'id' => 2,
                'text' => 'Hello world 2',
                'post_id' => null,
            ],
        ]);

        $this->assertEquals($json, (string) $comments);
    }
}
