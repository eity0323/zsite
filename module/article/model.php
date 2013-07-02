<?php
/**
 * The model file of article category of XiRangEPS.
 *
 * @copyright   Copyright 2013-2013 QingDao XiRang Network Infomation Co,LTD (www.xirang.biz)
 * @author      Chunsheng Wang <chunsheng@cnezsoft.com>
 * @package     article
 * @version     $Id$
 * @link        http://www.xirang.biz
 */
class articleModel extends model
{
    /** 
     * Get article info by id.
     * 
     * @param  int $articleId 
     * @param  int $imgMaxWidth 
     * @access public
     * @return void
     */
    public function getById($articleId, $imgMaxWidth = 0)
    {   
        $article = $this->dao->select('*')->from(TABLE_ARTICLE)->alias('t1')
            ->leftJoin(TABLE_ARTICLECATEGORY)->alias('t2')
            ->on('t1.id = t2.article')
            ->where('t1.id')->eq($articleId)
            ->andWhere('t2.site')->eq($this->app->site->id)
            ->fetch('', false);
        if($article and empty($article->tree)) $article->tree = 'article';
        $article->files   = $this->loadModel('file')->getByObject('article', $articleId);
        $article->content = $this->file->setImgSize($article->content, $imgMaxWidth);
        foreach($article->files as $file) if($file->isImage and $file->public) $article->images[] = $file;
        return $article;
    }   

    /** 
     * Get article list.
     * 
     * @param string $categories 
     * @param string $orderBy 
     * @param string $pager 
     * @access public
     * @return array
     */
    public function getList($categories, $orderBy, $pager = null)
    {
        return $this->dao->select('t1.*, t2.category')->from(TABLE_ARTICLE)->alias('t1')
            ->leftJoin(TABLE_ARTICLECATEGORY)->alias('t2')
            ->on('t1.id = t2.article')
            ->where('1 = 1')
            ->beginIF($categories)->andWhere('t2.category')->in($categories)->fi()
            ->orderBy($orderBy)
            ->page($pager, false)
            ->fetchAll('id', false);
    }

    /**
     * Get article pairs.
     * 
     * @param string $categories 
     * @param string $orderBy 
     * @param string $pager 
     * @access public
     * @return array
     */
    public function getPairs($categories, $orderBy, $pager = null)
    {
        return $this->dao->select('id, title')->from(TABLE_ARTICLE)->alias('t1')
            ->leftJoin(TABLE_ARTICLECATEGORY)->alias('t2')
            ->on('t1.id = t2.article')
            ->where('t2.site')->eq($this->app->site->id)
            ->beginIF($categories)->andWhere('t2.category')->in($categories)->fi()
            ->orderBy($orderBy)
            ->page($pager, false)
            ->fetchPairs('id', 'title', false);
    }

    /**
     * Get articles of an category.
     * 
     * @param string $categoryID  the category id
     * @param string $getFiles  get it's files or not
     * @param int $count 
     * @access public
     * @return array
     */
    public function getCategoryArticle($categoryID, $getFiles = false, $count = 10)
    {
        $this->loadModel('tree');
        $childs = $this->tree->getAllChildId($categoryID);
        $articles = $this->dao->select('id, title, author, addedDate, summary')
            ->from(TABLE_ARTICLE)->alias('t1')
            ->leftJoin(TABLE_ARTICLECATEGORY)->alias('t2')
            ->on('t1.id = t2.article')
            ->where('t2.site')->eq($this->app->site->id)
            ->andWhere('category')->in($childs)
            ->orderBy('id desc')->limit($count)->fetchAll('id', false);
        if(!$getFiles) return $articles;

        /* Fetch files. */
        $files = $this->loadModel('file')->getByObject('article', array_keys($articles));
        foreach($files as $file) $articles[$file->objectID]->files[] = $file;

        return $articles;
    }

    /**
     * Get the categories in other sites for an article.
     * 
     * @param  string $articleID 
     * @access public
     * @return array
     */
    public function getOtherSiteCategories($articleID)
    {
        return $this->dao->select('site, category')->from(TABLE_ARTICLECATEGORY)
            ->where('article')->eq($articleID)
            ->andWhere('site')->ne($this->session->site->id)
            ->fetchPairs('site', 'category', false);
    }

    /**
     * Get comment counts 
     * 
     * @param string $articles 
     * @access public
     * @return array
     */
    public function getCommentCounts($articles)
    {
        $comments = $this->dao->select('objectID as id, count("*") as count')->from(TABLE_COMMENT)
            ->where('objectID')->in($articles)
            ->andWhere('status')->eq(1)
            ->groupBy('objectID')
            ->fetchPairs('id', 'count', false);
        foreach($articles as $article) if(!isset($comments[$article])) $comments[$article] = 0;
        return $comments;
    }

    /**
     * Create an article.
     * 
     * @access public
     * @return void
     */
    public function create()
    {
        $article = fixer::input('post')->remove('categories')->get();
        $this->dao->insert(TABLE_ARTICLE)->data($article, false)->autoCheck()->batchCheck('title, content', 'notempty')->exec();
        if(!dao::isError())
        {
            $articleID = $this->dao->lastInsertID();
            $this->dao->update(TABLE_ARTICLE)->set('`order`')->eq($articleID)->where('id')->eq($articleID)->exec(false);    // set the order field.
            foreach($this->post->categories as $siteID => $categoryID)
            {
                $data = new stdClass();
                if(!$categoryID) continue;
                $data->article = $articleID;
                $data->category  = $categoryID;
                $this->dao->insert(TABLE_ARTICLECATEGORY)->data($data, false)->exec();
            }
        }
        return;
    }

    /**
     * Update an article.
     * 
     * @param string $articleID 
     * @access public
     * @return void
     */
    public function update($articleID)
    {
        $article = fixer::input('post')->add('editedDate', helper::now())->remove('categories')->get();
        $this->dao->update(TABLE_ARTICLE)->data($article, false)->autoCheck()->batchCheck('title, content', 'notempty')->where('id')->eq($articleID)->exec(false);
        if(!dao::isError())
        {
            foreach($this->post->categories as $siteID => $categoryID)
            {
                $this->dao->delete()->from(TABLE_ARTICLECATEGORY)
                    ->where('article')->eq($articleID)
                    ->andWhere('site')->eq($siteID)
                    ->exec(false);

                if($categoryID)
                {
                    $tree = $this->dao->findByID($categoryID)->from(TABLE_CATEGORY)->fetch('tree', false);
                    $data->category  = $categoryID;
                    $data->tree    = $tree;
                    $data->article = $articleID;
                    $data->site    = $siteID;
                    $this->dao->insert(TABLE_ARTICLECATEGORY)->data($data,false)->exec();
                }
            }
        }

        return;
    }

    /**
     * Delete an article
     * 
     * @param  string $articleID 
     * @access public
     * @return void
     */
    public function delete($articleID)
    {
        $this->dao->delete()->from(TABLE_ARTICLECATEGORY)->where('article')->eq($articleID)->exec(false);
        $this->dao->delete()->from(TABLE_ARTICLE)->where('id')->eq($articleID)->exec(false);
    }

    /**
     * Create digest for articles. 
     * 
     * @param string $articles 
     * @access public
     * @return void
     */
    public function createDigest($articles)
    {
        $this->loadModel('file');

        foreach($articles as $article)
        {
            $digestLength = $this->config->article->digest;
            /*  If the length of content litter than the setting, return directly. */
            if(mb_strlen($article->content) <= $digestLength)
            {
                $article->digest = $this->file->setImgSize($article->content);
            }
            else
            {
                /* substr the digest from the content. */
                if($article->content[$digestLength] != "\n")
                {
                    $newDigestLength = mb_strpos($article->content, "\n", $digestLength);
                    if($newDigestLength) $digestLength = $newDigestLength;
                }
                $digest = mb_substr($article->content, 0, $digestLength);
                $digest = tidy_repair_string($digest, array('show-body-only'=> true), 'UTF8');   // repair the unclosed tags.
                $digest = $this->file->setImgSize($digest);
                $article->digest = trim($digest);
            }
        }
    }
}
