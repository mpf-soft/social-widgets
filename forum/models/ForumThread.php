<?php
/**
 * Created by MPF Framework.
 * Date: 2015-03-13
 * Time: 13:45
 */

namespace mpf\modules\forum\models;

use app\models\PageTag;
use mpf\helpers\ArrayHelper;
use mpf\modules\forum\components\Config;
use mpf\modules\forum\components\UserAccess;
use mpf\datasources\sql\DataProvider;
use mpf\datasources\sql\DbModel;
use mpf\datasources\sql\DbRelations;
use mpf\datasources\sql\ModelCondition;
use mpf\web\helpers\Html;
use mpf\web\Session;
use mpf\WebApp;
use mpf\widgets\form\fields\ForumTextarea;

/**
 * Class ForumThread
 * @package app\models
 * @property int $id
 * @property int $user_id
 * @property int $subcategory_id
 * @property int $category_id
 * @property int $section_id
 * @property string $title
 * @property string $content
 * @property string $keywords
 * @property int $score
 * @property int $replies
 * @property int $first_level_replies
 * @property int $views
 * @property string $create_time
 * @property string $edit_time
 * @property int $edit_user_id
 * @property int $sticky
 * @property int $order
 * @property int $closed
 * @property int $last_reply_id
 * @property int $last_reply_user_id
 * @property int $last_reply_level
 * @property string $last_reply_date
 * @property \mpf\modules\forum\models\ForumSubcategory $subcategory
 * @property \app\models\User $owner
 * @property \app\models\User $editor
 * @property \app\models\User $lastActiveUser
 */
class ForumThread extends DbModel {

    /**
     * Get database table name.
     * @return string
     */
    public static function getTableName() {
        return "forum_threads";
    }

    /**
     * Get list of labels for each column. This are used by widgets like form, or table
     * to better display labels for inputs or table headers for each column.
     * @return array
     */
    public static function getLabels() {
        return [
            'id' => 'Id',
            'user_id' => 'Owner',
            'subcategory_id' => 'Subcategory',
            'category_id' => 'Category',
            'section_id' => 'Section',
            'title' => 'Title',
            'content' => 'Content',
            'keywords' => 'Keywords',
            'score' => 'Score',
            'replies' => 'Replies',
            'views' => 'Views',
            'create_time' => 'Create Time',
            'edit_time' => 'Edit Time',
            'edit_user_id' => 'Updated by',
            'sticky' => 'Sticky',
            'order' => 'Order',
            'closed' => 'Closed',
            'last_reply_id' => 'Last Reply',
            'last_reply_user_id' => 'Last User That Replied',
            'last_reply_date' => 'Last Reply Date'
        ];
    }

    /**
     * Return list of relations for current model
     * @return array
     */
    public static function getRelations() {
        return [
            'subcategory' => [DbRelations::BELONGS_TO, '\mpf\modules\forum\models\ForumSubcategory', 'subcategory_id'],
            'owner' => [DbRelations::BELONGS_TO, '\app\models\User', 'user_id'],
            'editor' => [DbRelations::BELONGS_TO, '\app\models\User', 'edit_user_id'],
            'lastActiveUser' => [DbRelations::BELONGS_TO, '\app\models\User', 'last_reply_user_id']
        ];
    }

    /**
     * List of rules for current model
     * @return array
     */
    public static function getRules() {
        return [
            ["title, content, keywords", "safe, required", "on" => "insert"],
            ["id, user_id, subcategory_id, category_id, section_id, title, content, keywords, score, replies, views, create_time, edit_time, edit_user_id, sticky, order, closed, last_reply_id, last_reply_user_id, last_reply_date", "safe", "on" => "search"]
        ];
    }

    /**
     * Find all threads for selected subcategory separated per page
     * @param $subcategory
     * @param int $page
     * @param int $threadsPerPage
     * @return static[]
     */
    public static function findAllForSubcategory($subcategory, $page = 1, $threadsPerPage = 20){
        $condition = new ModelCondition(['model' => __CLASS__]);
        $condition->compareColumn("subcategory_id", $subcategory);
        $condition->with = ['lastActiveUser', 'owner'];
        $condition->limit = $threadsPerPage;
        $condition->order = '`t`.`order` ASC, `t`.`id` DESC';
        $condition->offset = ($page - 1) * $threadsPerPage;
        return self::findAll($condition);
    }

    /**
     * @param int $section
     * @return static[]
     */
    public static function findRecent($section, $limit = 20, $offset = 0){
        $condition = new ModelCondition(['model' => ForumSubcategory::className()]);
        $condition->with = ['category'];
        $condition->compareColumn("category.section_id", $section);
        $condition->fields = ['t.id'];
        $ids = ArrayHelper::get()->transform(ForumSubcategory::findAll($condition), 'id');
        $condition = new ModelCondition(['model' => __CLASS__]);
        $condition->addInCondition('subcategory_id', $ids);
        $condition->order = "id DESC";
        $condition->limit = $limit;
        $condition->offset =$offset;
        return self::findAll($condition);
    }

    /**
     * Gets DataProvider used later by widgets like \mpf\widgets\datatable\Table to manage models.
     * @return \mpf\datasources\sql\DataProvider
     */
    public function getDataProvider() {
        $condition = new ModelCondition(['model' => __CLASS__]);

        foreach (["id", "user_id", "subcategory_id", "category_id", "section_id", "title", "content", "keywords", "score", "replies", "views", "create_time", "edit_time", "edit_user_id", "sticky", "order", "closed", "last_reply_id", "last_reply_user_id", "last_reply_date"] as $column) {
            if ($this->$column) {
                $condition->compareColumn($column, $this->$column, true);
            }
        }
        return new DataProvider([
            'modelCondition' => $condition
        ]);
    }

    /**
     * After first time save to be called to update category info
     * @param ForumSubcategory $subcategory
     * @return bool
     */
    public function publishNew(ForumSubcategory $subcategory = null){
        $subcategory = $subcategory?:ForumSubcategory::findByPk($this->subcategory_id);
        $subcategory->last_active_thread_id = $this->id;
        $subcategory->last_activity_time = date('Y-m-d H:i:s');
        $subcategory->last_active_user_id = $this->user_id;
        $subcategory->last_activity = 'create';
        $subcategory->number_of_threads = ForumThread::countByAttributes(['subcategory_id' => $subcategory->id]);
        return $subcategory->save();
    }

    public function getStatus(){
        if ($this->sticky){
            return 'sticky';
        } elseif ($this->closed){
            return 'closed';
        }
        return 'new';
    }

    /**
     * @var ForumUser2Section
     */
    protected $sectionUser;

    /**
     * @param $sectionId
     * @return ForumUser2Section
     */
    public function getSectionUser($sectionId){
        if (!$this->sectionUser){
            $this->sectionUser = ForumUser2Section::findByAttributes(['section_id' => $sectionId, 'user_id' => $this->user_id], ['with' => ['group', 'title']]);
        }
        return $this->sectionUser;
    }

    public function getContent(){
        return nl2br(ForumTextarea::parseText($this->content, PageTag::getTagRules(), [
            'linkRoot' => WebApp::get()->request()->getLinkRoot(),
            'webRoot' => WebApp::get()->request()->getWebRoot()
        ])) . Html::get()->scriptFile(WebApp::get()->request()->getWebRoot() . 'main/highlight/highlight.pack.js') .
        Html::get()->cssFile(WebApp::get()->request()->getWebRoot() . 'main/highlight/styles/github.css').
        Html::get()->script('hljs.tabReplace = \'    \';hljs.initHighlightingOnLoad();');
    }

    /**
     * @var bool
     */
    protected $_canEdit;

    /**
     * @param int $categoryId
     * @param int $sectionId
     * @return bool
     */
    public function canEdit($categoryId = null, $sectionId = null){
        if (!is_null($this->_canEdit)){
            return $this->_canEdit;
        }

        $categoryId = $categoryId?:$this->subcategory->category_id;
        $sectionId = $sectionId?:$this->subcategory->category->section_id;
        if (WebApp::get()->user()->isGuest()){ //added a fix for guests;
            return $this->_canEdit = false;
        }

        if (($this->closed && !($moderator =  UserAccess::get()->isCategoryModerator($categoryId, $sectionId))) || UserAccess::get()->isMuted($sectionId)){
            return $this->_canEdit = false;
        }

        if ($this->user_id == WebApp::get()->user()->id) {
            return $this->_canEdit = true;
        }
        return $this->_canEdit = isset($moderator)?$moderator:UserAccess::get()->isCategoryModerator($categoryId, $sectionId);
    }

    public $sessionViewsTempKey = "forum-threads-visited";

    public function updateViews(){
        if (Session::get()->exists($this->sessionViewsTempKey)){
            $threads = Session::get()->value($this->sessionViewsTempKey);
        } else {
            $threads = [];
        }
        if (!isset($threads[$this->id])){
            $this->views++;
            $this->save();
            $threads[$this->id] = true;
            Session::get()->set($this->sessionViewsTempKey, $threads);
        }
    }

    public function afterMove($oldSub){
        if ($oldSub == $this->subcategory_id)
            return; // same sub;
        $subcategory = ForumSubcategory::findByPk($oldSub);
        $subcategory->number_of_threads = ForumThread::countByAttributes(['subcategory_id' => $subcategory->id]);
        $subcategory->save();
        $subcategory = ForumSubcategory::findByPk($this->subcategory_id);
        $subcategory->number_of_threads = ForumThread::countByAttributes(['subcategory_id' => $subcategory->id]);
        $subcategory->save();
    }

    public function getAuthorIcon(){
        return Config::value('USER_ICON_FOLDER_URL') . ($this->owner->icon?:'default.png');
    }
}
