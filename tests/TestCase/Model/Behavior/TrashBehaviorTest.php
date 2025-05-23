<?php
declare(strict_types=1);

namespace Muffin\Trash\Test\TestCase\Model\Behavior;

use ArrayObject;
use Cake\Core\Configure;
use Cake\Core\Exception\CakeException;
use Cake\Database\Expression\QueryExpression;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\I18n\DateTime;
use Cake\ORM\Association\HasMany;
use Cake\ORM\Entity;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;
use Muffin\Trash\Model\Behavior\TrashBehavior;

class TrashBehaviorTest extends TestCase
{
    /**
     * Fixtures to load.
     *
     * @var array
     */
    protected array $fixtures = [
        'plugin.Muffin/Trash.Articles',
        'plugin.Muffin/Trash.Comments',
        'plugin.Muffin/Trash.Users',
        'plugin.Muffin/Trash.ArticlesUsers',
        'plugin.Muffin/Trash.CompositeArticlesUsers',
    ];

    protected Table $Users;
    protected Table $CompositeArticlesUsers;
    protected Table $Comments;
    protected Table $Articles;
    protected TrashBehavior $Behavior;

    /**
     * Runs before each test.
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->Users = $this->getTableLocator()->get('Muffin/Trash.Users', ['table' => 'trash_users']);
        $this->Users->belongsToMany('Articles', [
            'className' => 'Muffin/Trash.Articles',
            'joinTable' => 'trash_articles_users',
            'foreignKey' => 'user_id',
            'targetForeignKey' => 'article_id',
        ]);
        $this->Users->addBehavior('Muffin/Trash.Trash');

        $this->CompositeArticlesUsers = $this->getTableLocator()->get(
            'Muffin/Trash.CompositeArticlesUsers',
            ['table' => 'trash_composite_articles_users']
        );
        $this->CompositeArticlesUsers->addBehavior('Muffin/Trash.Trash');

        $this->Comments = $this->getTableLocator()->get('Muffin/Trash.Comments', ['table' => 'trash_comments']);
        $this->Comments->belongsTo('Articles', [
            'className' => 'Muffin/Trash.Articles',
            'foreignKey' => 'article_id',
        ]);
        $this->Comments->addBehavior('CounterCache', ['Articles' => [
            'comment_count',
            'total_comment_count' => ['finder' => 'withTrashed'],
        ]]);
        $this->Comments->addBehavior('Muffin/Trash.Trash');

        $this->Articles = $this->getTableLocator()->get('Muffin/Trash.Articles', ['table' => 'trash_articles']);
        $this->Articles->addBehavior('Muffin/Trash.Trash');
        $this->Articles->hasMany('Comments', [
            'className' => 'Muffin/Trash.Comments',
            'foreignKey' => 'article_id',
            'sort' => ['Comments.id' => 'ASC'],
        ]);
        $this->Articles->belongsToMany('Users', [
            'className' => 'Muffin/Trash.Users',
            'joinTable' => 'trash_articles_users',
            'foreignKey' => 'article_id',
            'targetForeignKey' => 'user_id',
            'cascadeCallbacks' => true,
        ]);
        $this->Articles->hasMany('CompositeArticlesUsers', [
            'className' => 'Muffin/Trash.CompositeArticlesUsers',
            'foreignKey' => 'article_id',
        ]);

        $this->Behavior = $this->Articles->behaviors()->Trash;
    }

    /**
     * Runs after each test.
     *
     * @return void
     */
    public function tearDown(): void
    {
        parent::tearDown();

        $this->getTableLocator()->clear();
        unset($this->Users, $this->Comments, $this->Articles, $this->Behavior);
    }

    /**
     * Test the beforeFind callback.
     *
     * @return void
     */
    public function testBeforeFind()
    {
        $result = $this->Articles->find('all')->toArray();
        $this->assertCount(1, $result);
    }

    /**
     * Test the beforeFind callback when using the trash field in a comparison
     *
     * @return void
     */
    public function testBeforeFindWithTrashFieldInComparison()
    {
        $query = $this->Articles->find('all');

        $result = $query->where(
            [$this->Articles->aliasField('trashed') . ' >= ' => new DateTime('-1 day')]
        )->toArray();
        $this->assertCount(2, $result);
    }

    /**
     * Test the beforeFind callback when using the trash field in a between expression
     *
     * @return void
     */
    public function testBeforeFindWithTrashFieldInBetweenComparison()
    {
        $query = $this->Articles->find('all');
        $trashedField = $this->Articles->aliasField('trashed');
        $result = $query->where(function (QueryExpression $exp) use ($trashedField) {
            return $exp->between($trashedField, new DateTime('-1 day'), new DateTime('+1 day'));
        })->toArray();
        $this->assertCount(2, $result);
    }

    /**
     * Test the beforeDelete callback.
     *
     * @return void
     */
    public function testBeforeDelete()
    {
        $article = $this->Articles->get(1);
        $result = $this->Articles->delete($article);

        $this->assertTrue($result);
        $this->assertCount(3, $this->Articles->find('withTrashed'));
    }

    /**
     * Test the beforeDelete callback.
     *
     * @return void
     */
    public function testBeforeDeleteAbort()
    {
        $article = $this->Articles->get(1);

        $this->Articles->getEventManager()->on(
            'Model.beforeSave',
            [],
            function (Event $event, EntityInterface $entity, ArrayObject $options) {
                $entity->setError('id', 'Save aborted');
                $event->setResult(false);
                $event->stopPropagation();
            }
        );

        $result = $this->Articles->delete($article);

        $this->assertFalse($result);
        $this->assertArrayHasKey('id', $article->getErrors());
    }

    /**
     * Test the beforeDelete callback with the purge option
     *
     * @return void
     */
    public function testBeforeDeletePurge()
    {
        $article = $this->Articles->get(1);
        $result = $this->Articles->delete($article, ['purge' => true]);

        $this->assertTrue($result);
        $this->assertCount(2, $this->Articles->find('withTrashed'));
    }

    /**
     * Tests that the options passed to the `delete()` method are being passed on into
     * the cascading delete process.
     *
     * @return void
     */
    public function testDeleteOptionsArePassedToCascadingDeletes()
    {
        $association = $this->Articles->Comments;
        $association->setDependent(true);
        $association->setCascadeCallbacks(true);

        $hasDeleteOptionsBefore = false;
        $hasDeleteOptionsAfter = false;
        $this->Comments->getEventManager()->on(
            'Model.beforeDelete',
            ['priority' => 1],
            function (Event $event, EntityInterface $entity, ArrayObject $options) use (&$hasDeleteOptionsBefore) {
                if (isset($options['deleteOptions'])) {
                    $hasDeleteOptionsBefore = true;
                }
            }
        );
        $this->Comments->getEventManager()->on(
            'Model.afterDelete',
            function (Event $event, EntityInterface $entity, ArrayObject $options) use (&$hasDeleteOptionsAfter) {
                if (isset($options['deleteOptions'])) {
                    $hasDeleteOptionsAfter = true;
                }
            }
        );

        $article = $this->Articles->get(1);
        $result = $this->Articles->delete($article, [
            'deleteOptions' => true,
        ]);

        $this->assertTrue($result);
        $this->assertTrue($hasDeleteOptionsBefore);
        $this->assertTrue($hasDeleteOptionsAfter);
    }

    /**
     * Tests that the options passed to the `delete()` method are being passed on into
     * the saving process.
     *
     * @return void
     */
    public function testDeleteOptionsArePassedToSave()
    {
        $association = $this->Articles->Comments;
        $association->setDependent(true);
        $association->setCascadeCallbacks(true);

        $mainHasDeleteOptions = false;
        $dependentHasDeleteOptions = false;
        $dependentIsNotPrimary = false;
        $this->Articles->getEventManager()->on(
            'Model.beforeSave',
            function (Event $event, EntityInterface $entity, ArrayObject $options) use (&$mainHasDeleteOptions) {
                if (isset($options['deleteOptions'])) {
                    $mainHasDeleteOptions = true;
                }
            }
        );
        $this->Comments->getEventManager()->on(
            'Model.beforeSave',
            function (
                Event $event,
                EntityInterface $entity,
                ArrayObject $options
            ) use (
                &$dependentHasDeleteOptions,
                &$dependentIsNotPrimary
            ) {
                if (isset($options['deleteOptions'])) {
                    $dependentHasDeleteOptions = true;
                }

                $dependentIsNotPrimary = $options['_primary'] === false;
            }
        );

        $article = $this->Articles->get(1);
        $result = $this->Articles->delete($article, [
            'deleteOptions' => true,
        ]);

        $this->assertTrue($result);
        $this->assertTrue($mainHasDeleteOptions);
        $this->assertTrue($dependentHasDeleteOptions);
        $this->assertTrue($dependentIsNotPrimary);
    }

    /**
     * Test trash function with composite primary keys
     *
     * @return void
     */
    public function testTrashComposite()
    {
        $item = $this->CompositeArticlesUsers->get([3, 1]);
        $result = $this->CompositeArticlesUsers->trash($item);

        $this->assertTrue($result);
        $this->assertCount(1, $this->CompositeArticlesUsers->find('onlyTrashed'));
    }

    /**
     * Test trash function
     *
     * @return void
     */
    public function testTrash()
    {
        $article = $this->Articles->get(1);
        $result = $this->Articles->trash($article);

        $this->assertTrue($result);
        $this->assertCount(3, $this->Articles->find('withTrashed'));

        // Ensure the junction table record is not deleted
        $this->assertSame(
            2,
            $this->getTableLocator()
                ->get('ArticlesUsers', ['table' => 'trash_articles_users'])
                ->find()
                ->count()
        );
    }

    /**
     * Test trash function
     *
     * @return void
     */
    public function testTrashNoPrimaryKey()
    {
        $article = $this->Articles->get(1);
        $article->unset('id');
        $this->expectException(CakeException::class);
        $this->Articles->trash($article);
    }

    /**
     * Test trash function with property not accessible
     *
     * @return void
     */
    public function testTrashNonAccessibleProperty()
    {
        $article = $this->Articles->get(1);
        $article->setAccess('trashed', false);
        $result = $this->Articles->trash($article);

        $this->assertTrue($result);
        $this->assertCount(3, $this->Articles->find('withTrashed'));
    }

    public function testFindWithImplicitCondition()
    {
        $this->assertCount(2, $this->Articles->find()->where([
            'trashed IS NOT' => null,
        ]));

        $this->assertCount(2, $this->Articles->find()->where([
            'Articles.trashed IS NOT' => null,
        ]));
    }

    /**
     * Test it can find only trashed records.
     *
     * @return void
     */
    public function testFindOnlyTrashed()
    {
        $this->assertCount(2, $this->Articles->find('onlyTrashed'));
    }

    /**
     * Test it can find with trashed records.
     *
     * @return void
     */
    public function testFindWithTrashed()
    {
        $this->assertCount(3, $this->Articles->find('withTrashed'));
    }

    /**
     * Test it can empty all records from the trash.
     *
     * @return void
     */
    public function testEmptyTrash()
    {
        $this->Articles->emptyTrash();

        $this->assertCount(1, $this->Articles->find());
    }

    /**
     * Test it can restore all records in the trash.
     *
     * @return void
     */
    public function testRestoreTrash()
    {
        $this->Articles->restoreTrash();

        $this->assertCount(3, $this->Articles->find());
    }

    /**
     * Test it can restore all records in the trash.
     *
     * @return void
     */
    public function testRestoreDirtyEntity()
    {
        $entity = $this->Articles->find('onlyTrashed')->first();
        $entity->setDirty('title');

        $this->expectException(CakeException::class);
        $this->Articles->restoreTrash($entity);
    }

    /**
     * Test it can trash all records.
     *
     * @return void
     */
    public function testTrashAll()
    {
        $this->assertCount(1, $this->Articles->find());

        $this->Articles->trashAll('1 = 1');
        $this->assertCount(0, $this->Articles->find());
    }

    /**
     * Test it can restore one record from the trash.
     *
     * @return void
     */
    public function testRestoreTrashEntity()
    {
        $this->Articles->restoreTrash(new Entity([
            'id' => 2,
        ], ['markNew' => false, 'markClean' => true]));

        $this->assertCount(2, $this->Articles->find());
    }

    /**
     * Test it can find records with a hasMany association.
     *
     * @return void
     */
    public function testFindingRecordWithHasManyAssoc()
    {
        $result = $this->Articles->get(primaryKey: 1, contain: ['Comments']);
        $this->assertCount(1, $result->comments);
    }

    /**
     * Test it can find records with HABTM association.
     *
     * @return void
     */
    public function testFindingRecordWithBelongsToManyAssoc()
    {
        $result = $this->Users->get(primaryKey: 1, contain: ['Articles']);
        $this->assertCount(1, $result->articles);
    }

    /**
     * Test that it can work alongside CounterCache behavior.
     *
     * @return void
     */
    public function testInteroperabilityWithCounterCache()
    {
        $comment = $this->Comments->get(1);
        $this->Comments->delete($comment);
        $result = $this->Articles->get(1);

        $this->assertEquals(0, $result->comment_count);
        $this->assertEquals(2, $result->total_comment_count);
    }

    /**
     * Test that it can work alongside CounterCache behavior and trash method.
     *
     * @return void
     */
    public function testInteroperabilityWithCounterCacheAndTrashMethod()
    {
        $comment = $this->Comments->get(1);
        $this->Comments->trash($comment);
        $result = $this->Articles->get(1);

        $this->assertEquals(0, $result->comment_count);
        $this->assertEquals(2, $result->total_comment_count);
    }

    /**
     * Ensure that when trashing it will cascade into related dependent records
     *
     * @return void
     */
    public function testCascadingTrash()
    {
        $association = $this->Articles->Comments;
        $association->setDependent(true);
        $association->setCascadeCallbacks(true);

        $article = $this->Articles->get(1);
        $this->Articles->trash($article);

        $article = $this->Articles->find('withTrashed')
            ->where(['Articles.id' => 1])
            ->contain(['Comments' => [
                'finder' => 'withTrashed',
            ]])
            ->first();

        $this->assertNotEmpty($article->trashed);
        $this->assertInstanceOf(DateTime::class, $article->trashed);

        $this->assertNotEmpty($article->comments[0]->trashed);
        $this->assertInstanceOf(DateTime::class, $article->comments[0]->trashed);
    }

    /**
     * When cascadeTrashAndRestore = false
     * Ensure that when trashing it will not cascade into related dependent records
     *
     * @return void
     */
    public function testDisabledCascadingForTrash()
    {
        $association = $this->Articles->Comments;
        $association->setDependent(true);
        $association->setCascadeCallbacks(true);

        // disable cascade trash/restore
        $this->Articles->behaviors()->get('Trash')->setConfig('cascadeOnTrash', false);

        $article = $this->Articles->get(1);
        $this->Articles->trash($article);

        $article = $this->Articles->find('withTrashed')
            ->where(['Articles.id' => 1])
            ->contain(['Comments' => [
                'finder' => 'withTrashed',
            ]])
            ->first();

        $this->assertNotEmpty($article->trashed);
        $this->assertInstanceOf(DateTime::class, $article->trashed);

        // expect not trashed
        $this->assertEmpty($article->comments[0]->trashed);
    }

    public function testCascadingUntrashOptionsArePassedToSave()
    {
        $association = $this->Articles->Comments;
        $association->setDependent(true);
        $association->setCascadeCallbacks(true);

        $this->Articles->Comments->getTarget()->trashAll([]);
        $this->assertEquals(0, $this->Articles->Comments->getTarget()->find()->count());

        $this->Articles->trashAll([]);
        $this->assertEquals(0, $this->Articles->find()->count());

        $article = $this->Articles
            ->find('withTrashed')
            ->where(['Articles.id' => 1])
            ->first();

        $mainHasRestoreOptions = false;
        $dependentHasRestoreOptions = false;
        $dependentIsNotPrimary = false;
        $this->Articles->getEventManager()->on(
            'Model.beforeSave',
            function (Event $event, EntityInterface $entity, ArrayObject $options) use (&$mainHasRestoreOptions) {
                if (isset($options['restoreOptions'])) {
                    $mainHasRestoreOptions = true;
                }
            }
        );
        $this->Comments->getEventManager()->on(
            'Model.beforeSave',
            function (
                Event $event,
                EntityInterface $entity,
                ArrayObject $options
            ) use (
                &$dependentHasRestoreOptions,
                &$dependentIsNotPrimary
            ) {
                if (isset($options['restoreOptions'])) {
                    $dependentHasRestoreOptions = true;
                }

                $dependentIsNotPrimary = $options['_primary'] === false;
            }
        );

        $result = $this->Articles->cascadingRestoreTrash($article, [
            'restoreOptions' => true,
        ]);

        $this->assertInstanceOf(EntityInterface::class, $result);
        $this->assertTrue($mainHasRestoreOptions);
        $this->assertTrue($dependentHasRestoreOptions);
        $this->assertTrue($dependentIsNotPrimary);
    }

    /**
     * Tests that cascading restore with an entity specified will restore that entity record,
     * and the dependent records.
     *
     * @return void
     */
    public function testCascadingUntrashEntity()
    {
        $association = $this->Articles->Comments;
        $association->setDependent(true);
        $association->setCascadeCallbacks(true);

        $association = $this->Articles->CompositeArticlesUsers;
        $association->setDependent(true);
        $association->setCascadeCallbacks(true);

        $this->Articles->Comments->getTarget()->trashAll([]);
        $this->assertEquals(0, $this->Articles->Comments->getTarget()->find()->count());

        $this->Articles->CompositeArticlesUsers->getTarget()->trashAll([]);
        $this->assertEquals(0, $this->Articles->CompositeArticlesUsers->getTarget()->find()->count());

        $this->Articles->trashAll([]);
        $this->assertEquals(0, $this->Articles->find()->count());

        $article = $this->Articles
            ->find('withTrashed')
            ->where(['Articles.id' => 1])
            ->contain([
                'Comments' => [
                    'finder' => 'withTrashed',
                ],
                'CompositeArticlesUsers' => [
                    'finder' => 'withTrashed',
                ],
            ])
            ->first();

        $this->assertNotEmpty($article->trashed);
        $this->assertInstanceOf(DateTime::class, $article->trashed);

        $this->assertNotEmpty($article->comments[0]->trashed);
        $this->assertInstanceOf(DateTime::class, $article->comments[0]->trashed);

        $this->assertNotEmpty($article->composite_articles_users[0]->trashed);
        $this->assertInstanceOf(DateTime::class, $article->composite_articles_users[0]->trashed);

        $unrelatedComment = $this->Articles->Comments->getTarget()
            ->findById(3)
            ->find('withTrashed')
            ->first();
        $this->assertNotEquals($article->id, $unrelatedComment->article_id);
        $this->assertNotEmpty($unrelatedComment->trashed);
        $this->assertInstanceOf(DateTime::class, $unrelatedComment->trashed);

        $unrelatedArticleUser = $this->Articles->CompositeArticlesUsers->getTarget()
            ->findByArticleId(3)
            ->find('withTrashed')
            ->first();
        $this->assertNotEquals($article->id, $unrelatedArticleUser->article_id);
        $this->assertNotEmpty($unrelatedArticleUser->trashed);
        $this->assertInstanceOf(DateTime::class, $unrelatedArticleUser->trashed);

        $this->assertInstanceOf(
            EntityInterface::class,
            $this->Articles->cascadingRestoreTrash($article)
        );

        $article = $this->Articles
            ->find()
            ->where(['Articles.id' => 1])
            ->contain(['Comments', 'CompositeArticlesUsers'])
            ->first();

        $this->assertEmpty($article->trashed);
        $this->assertEmpty($article->comments[0]->trashed);
        $this->assertEmpty($article->composite_articles_users[0]->trashed);

        $unrelatedComment = $this->Articles->Comments->getTarget()
            ->findById(3)
            ->find('withTrashed')
            ->first();
        $this->assertNotEquals($article->id, $unrelatedComment->article_id);
        $this->assertNotEmpty($unrelatedComment->trashed);
        $this->assertInstanceOf(DateTime::class, $unrelatedComment->trashed);

        $unrelatedArticleUser = $this->Articles->CompositeArticlesUsers->getTarget()
            ->findByArticleId(3)
            ->find('withTrashed')
            ->first();
        $this->assertNotEquals($article->id, $unrelatedArticleUser->article_id);
        $this->assertNotEmpty($unrelatedArticleUser->trashed);
        $this->assertInstanceOf(DateTime::class, $unrelatedArticleUser->trashed);
    }

    /**
     * Tests that cascading restore without specifying an entity will restore all records.
     *
     * @return void
     */
    public function testCascadingUntrashAll()
    {
        $association = $this->Articles->Comments;
        $association->setDependent(true);
        $association->setCascadeCallbacks(true);

        $association = $this->Articles->CompositeArticlesUsers;
        $association->setDependent(true);
        $association->setCascadeCallbacks(true);

        $this->Articles->Comments->getTarget()->trashAll([]);
        $this->assertEquals(0, $this->Articles->Comments->getTarget()->find()->count());

        $this->Articles->CompositeArticlesUsers->getTarget()->trashAll([]);
        $this->assertEquals(0, $this->Articles->CompositeArticlesUsers->getTarget()->find()->count());

        $this->Articles->trashAll([]);
        $this->assertEquals(0, $this->Articles->find()->count());

        $article = $this->Articles
            ->find('withTrashed')
            ->where(['Articles.id' => 1])
            ->contain([
                'Comments' => [
                    'finder' => 'withTrashed',
                ],
                'CompositeArticlesUsers' => [
                    'finder' => 'withTrashed',
                ],
            ])
            ->first();

        $this->assertNotEmpty($article->trashed);
        $this->assertInstanceOf(DateTime::class, $article->trashed);

        $this->assertNotEmpty($article->comments[0]->trashed);
        $this->assertInstanceOf(DateTime::class, $article->comments[0]->trashed);

        $this->assertNotEmpty($article->composite_articles_users[0]->trashed);
        $this->assertInstanceOf(DateTime::class, $article->composite_articles_users[0]->trashed);

        $this->assertEquals(8, $this->Articles->cascadingRestoreTrash());

        $article = $this->Articles
            ->find()
            ->where(['Articles.id' => 1])
            ->contain(['Comments', 'CompositeArticlesUsers'])
            ->first();

        $this->assertEmpty($article->trashed);
        $this->assertEmpty($article->comments[0]->trashed);
        $this->assertEmpty($article->composite_articles_users[0]->trashed);

        $this->assertEquals(3, $this->Articles->Comments->getTarget()->find()->count());
        $this->assertEquals(2, $this->Articles->CompositeArticlesUsers->getTarget()->find()->count());
        $this->assertEquals(3, $this->Articles->find()->count());
    }

    /**
     * Tests that cascading restore returns the expected value on failure.
     *
     * @return void
     */
    public function testCascadingUntrashFailure()
    {
        $association = $this->Articles->Comments;
        $association->setDependent(true);
        $association->setCascadeCallbacks(true);
        $association->getEventManager()->on('Model.beforeSave', function () {
            return false;
        });

        $association->getTarget()->trashAll([]);
        $this->assertEquals(0, $association->getTarget()->find()->count());

        $this->Articles->trashAll([]);
        $this->assertEquals(0, $this->Articles->find()->count());

        $article = $this->Articles
            ->find('withTrashed')
            ->where(['Articles.id' => 1])
            ->first();

        $this->assertFalse($this->Articles->cascadingRestoreTrash($article));
    }

    /**
     * Ensure that removing dependent records via the replace save strategy will trash those records
     *
     * @return void
     */
    public function testTrashDependentViaReplaceSaveStrategy()
    {
        $association = $this->Articles->Comments;
        $association->setDependent(true);
        $association->setCascadeCallbacks(true);
        $association->setSaveStrategy(HasMany::SAVE_REPLACE);

        $article = $this->Articles->get(primaryKey: 1, contain: ['Comments']);

        $this->assertEquals(1, $article->comments[0]->id);
        $this->assertEmpty($article->comments[0]->trashed);

        $article->set('comments', []);
        $article->setDirty('comments', true);

        $this->assertInstanceOf(EntityInterface::class, $this->Articles->save($article));

        $article = $this->Articles
            ->find('withTrashed')
            ->where(['Articles.id' => 1])
            ->contain(['Comments' => [
                'finder' => 'withTrashed',
            ]])
            ->first();

        $this->assertNotEmpty($article->comments);
        $this->assertEquals(1, $article->comments[0]->id);
        $this->assertNotEmpty($article->comments[0]->trashed);
        $this->assertInstanceOf(DateTime::class, $article->comments[0]->trashed);
    }

    /**
     * Test that getTrashField() throws exception if "field" is not specified
     * and cannot be introspected.
     *
     * @return void
     */
    public function testGetTrashFieldException()
    {
        $this->expectException(CakeException::class);
        $this->expectExceptionMessage('TrashBehavior: "field" config needs to be provided.');

        $trash = new TrashBehavior($this->getTableLocator()->get('ArticlesUsers', ['table' => 'trash_articles_users']));
        $trash->getTrashField();
    }

    /**
     * Test that getTrashField() uses configured value
     *
     * @return void
     */
    public function testGetTrashFieldUsesConfiguredValue()
    {
        $trash = new TrashBehavior($this->Users, ['field' => 'deleted']);
        $this->assertEquals('Users.deleted', $trash->getTrashField());

        Configure::write('Muffin/Trash.field', 'trashed');
        $trash = new TrashBehavior($this->Users);
        $this->assertEquals('Users.trashed', $trash->getTrashField());
    }

    /**
     * Test that getTrashField() uses a default value if no field is configured
     * and that it sets the name of the field in the config array.
     *
     * @return void
     */
    public function testGetTrashFieldFallbackToDefault()
    {
        $trash = new TrashBehavior($this->Articles);

        $this->assertEmpty($trash->getConfig('field'));
        $this->assertEquals('Articles.trashed', $trash->getTrashField());
        $this->assertEquals('trashed', $trash->getConfig('field'));
    }

    /**
     * Test that getTrashField() defaults to deleted or trashed
     * when found in schema and not specified
     *
     * @return void
     */
    public function testGetTrashFieldSchemaIntrospection()
    {
        $this->assertEquals(
            'Articles.trashed',
            $this->Articles->behaviors()->get('Trash')->getTrashField()
        );
    }

    /**
     * Test the implementedEvents method.
     *
     * @return void
     */
    public function testImpEInvalidArgumentException()
    {
        $trash = new TrashBehavior($this->Users, ['events' => $this->Articles]);

        $this->expectException(InvalidArgumentException::class);
        $trash->implementedEvents();
    }

    /**
     * Test the implementedEvents method.
     *
     * @dataProvider provideConfigsForImplementedEventsTest
     * @param array $config Initial behavior config.
     * @param array $implementedEvents Expected implementedEvents.
     * @return void
     */
    public function testImplementedEvents(array $config, array $implementedEvents)
    {
        $trash = new TrashBehavior($this->Users, $config);

        $this->assertEquals($implementedEvents, $trash->implementedEvents());
    }

    /**
     * Provide configs for the implementedEvents test.
     *
     * @return array
     */
    public static function provideConfigsForImplementedEventsTest()
    {
        return [
            'No event config inherits default events' => [
                [],
                [
                    'Model.beforeDelete' => [
                        'callable' => 'beforeDelete',
                    ],
                    'Model.beforeFind' => [
                        'callable' => 'beforeFind',
                    ],
                ],
            ],
            'Event config with empty array inherits default events' => [
                [
                    'events' => [],
                ],
                [
                    'Model.beforeDelete' => [
                        'callable' => 'beforeDelete',
                    ],
                    'Model.beforeFind' => [
                        'callable' => 'beforeFind',
                    ],
                ],
            ],
            'Event config with false disables default events' => [
                [
                    'events' => false,
                ],
                [],
            ],
            'Event config with event key as value' => [
                [
                    'events' => [
                        'Model.beforeDelete',
                    ],
                ],
                [
                    'Model.beforeDelete' => [
                        'callable' => 'beforeDelete',
                    ],
                ],
            ],
            'Event config with method name as value' => [
                [
                    'events' => [
                        'Model.beforeFind' => 'beforeFind',
                    ],
                ],
                [
                    'Model.beforeFind' => [
                        'callable' => 'beforeFind',
                    ],
                ],
            ],
            'Event config with callables' => [
                [
                    'events' => [
                        'Model.beforeDelete' => [
                            'callable' => function () {
                            },
                        ],
                        'Model.beforeFind' => [
                            'callable' => ['', 'beforeDelete'],
                            'passParams' => true,
                        ],
                    ],
                ],
                [
                    'Model.beforeDelete' => [
                        'callable' => function () {
                        },
                    ],
                    'Model.beforeFind' => [
                        'callable' => ['', 'beforeDelete'],
                        'passParams' => true,
                    ],
                ],
            ],
            'Event config with multiple options' => [
                [
                    'events' => [
                        'Model.beforeDelete' => [
                            'callable' => 'beforeDelete',
                            'passParams' => true,
                        ],
                    ],
                ],
                [
                    'Model.beforeDelete' => [
                        'callable' => 'beforeDelete',
                        'passParams' => true,
                    ],
                ],
            ],
            'Event config with default and event priorities' => [
                [
                    'priority' => 1,
                    'events' => [
                        'Model.beforeDelete',
                        'Model.beforeFind' => ['priority' => 5],
                    ],
                ],
                [
                    'Model.beforeDelete' => [
                        'callable' => 'beforeDelete',
                        'priority' => 1,
                    ],
                    'Model.beforeFind' => [
                        'callable' => 'beforeFind',
                        'priority' => 5,
                    ],
                ],
            ],
        ];
    }
}
