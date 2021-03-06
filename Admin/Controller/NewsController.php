<?php
namespace Octo\News\Admin\Controller;

use DateTime;
use b8\Form;
use Octo\Store;
use Octo\Admin\Controller;
use Octo\Admin\Form as FormElement;
use Octo\Admin\Menu;
use Octo\Event;
use Octo\Articles\Model\Article;
use Octo\System\Model\ContentItem;

class NewsController extends Controller
{
    /**
     * @var Scope
     */
    protected $scope;
    /*
     * @var Article Type
     */
    protected $articleType;
    /*
     * @var Lowercase Article Type
     */
    protected $lowerArticleType;

    protected $articleTypeMulti;

    /**
     * @var string Article type
     */
    protected $modelType = '\Octo\Articles\Model\Article';

    /**
     * Return the menu nodes required for this controller
     *
     * @param Menu $menu
     * @author James Inman
     */
    public static function registerMenus(Menu $menu)
    {
        $news = $menu->addRoot('News', '#')->setIcon('bullhorn');
        $news->addChild(new Menu\Item('Add Article', '/news/add'));
        $manage = new Menu\Item('Manage Articles', '/news');
        $manage->addChild(new Menu\Item('Edit Article', '/news/edit', true));
        $manage->addChild(new Menu\Item('Delete Article', '/news/delete', true));
        $news->addChild($manage);
        $categories = new Menu\Item('Manage Categories', '/categories/manage/news');
        $news->addChild($categories);
    }

    /**
     * Setup initial menu
     *
     * @return void
     * @author James Inman
     */
    public function init()
    {
        $this->userStore = Store::get('User');
        $this->categoryStore = Store::get('Category');
        $this->contentItemStore = Store::get('ContentItem');
        $this->articleStore = Store::get('Article');

        $this->scope = 'news';
        $this->articleType = 'Article';
        $this->lowerArticleType = 'article';
        $this->articleTypeMulti = 'Articles';

        $this->setTitle($this->articleTypeMulti);
        $this->addBreadcrumb($this->articleTypeMulti, '/' . $this->scope);
    }

    /**
     * List Articles Blog or News
     *
     * @return void
     * @author Leszek Pietrzak
     *
     */
    public function index()
    {
        //IMHO limit should be included in url, easy to set by user
        $pagination = [
            'current' => (int)$this->request->getParam('p', 1),
            'limit' => 20,
            'uri' => $this->request->getPath() . '?',
        ];

        $this->setTitle('Manage ' . $this->articleTypeMulti, ucwords($this->scope));


        $category = !empty($this->content['category']) ? $this->content['category'] : null;

        $criteria = [];
        $params = [];

        $criteria[] = 'c.scope = :scope';
        $params[':scope'] = $this->scope;

        if (!is_null($category)) {
            $criteria[] = 'category_id = :category_id';
            $params[':category_id'] = $category;
        }

        $current = $pagination['current'];
        $limit = $pagination['limit'];
        $query = $this->articleStore->query($current, $limit, ['publish_date', 'DESC'], $criteria, $params);
        $query->join('category', 'c', 'c.id = article.category_id');

        $pagination['total'] = $query->getCount();
        $query->execute();

        $this->view->pagination = $pagination;
        $this->view->articles = $query->fetchAll();
    }

    public function add()
    {
        $this->setTitle('Add ' . $this->articleType, ucwords($this->scope));
        $this->addBreadcrumb('Add ' . $this->articleType, '/' . $this->scope . '/add');

        if ($this->request->getMethod() == 'POST') {
            $form = $this->newsForm($this->getParams());

            if ($form->validate()) {
                try {
                    $hash = md5($this->getParam('content'));

                    $contentItem = $this->contentItemStore->getById($hash);
                    if (!$contentItem) {
                        $contentItem = new ContentItem();
                        $contentItem->setId($hash);
                        $contentItem->setContent(json_encode(array('content' => $this->getParam('content'))));
                        $contentItem = $this->contentItemStore->saveByInsert($contentItem);
                    }

                    $modelName = $this->modelType;
                    $article = new $modelName();
                    $article->setValues($this->getParams());

                    if (empty($this->getParam('image_id'))) {
                        $article->setImageId(null);
                    }

                    $article->setUserId($this->currentUser->getId());
                    $article->setContentItemId($hash);
                    $article->setCreatedDate(new \DateTime());
                    $article->setUpdatedDate(new \DateTime());
                    $article->setSlug($article->generateSlug());
                    if (empty($this->getParam('summary'))) {
                        $article->setSummary($article->generateSummary());
                    }

                    Event::trigger('before' . $this->articleType . 'Save', $article);
                    $article = $this->articleStore->save($article);

                    $this->successMessage($article->getTitle() . ' was added successfully.', true);
                    header('Location: /' . $this->config->get('site.admin_uri') . '/' . $this->scope);
                } catch (Exception $e) {
                    $this->errorMessage(
                        'There was an error adding the ' . $this->lowerArticleType . '. Please try again.'
                    );
                }
            } else {
                $this->errorMessage('There was an error adding the ' . $this->lowerArticleType . '. Please try again.');
            }

            $this->view->form = $form->render();
        } else {
            $form = $this->newsForm();
            $this->view->form = $form->render();
        }
    }

    public function edit($newsId)
    {
        $article = $this->articleStore->getById($newsId);
        $this->setTitle($article->getTitle());
        $this->addBreadcrumb($article->getTitle(), '/' . $this->scope. '/edit/' . $newsId);

        $this->view->title = $article->getTitle();

        if ($this->request->getMethod() == 'POST') {
            $values = array_merge(array('id' => $newsId), $this->getParams());
            $form = $this->newsForm($values, 'edit');

            if ($form->validate()) {
                try {
                    $hash = md5($this->getParam('content'));

                    $contentItem = $this->contentItemStore->getById($hash);
                    if (!$contentItem) {
                        $contentItem = new ContentItem();
                        $contentItem->setId($hash);
                        $contentItem->setContent(json_encode(array('content' => $this->getParam('content'))));
                        $contentItem = $this->contentItemStore->saveByInsert($contentItem);
                    }
                    /*hack for validateInt with empty values*/
                    $formFilter = $this->getParams();
                    $article->setValues($formFilter);
                    $article->setUserId($this->currentUser->getId());
                    $article->setContentItemId($hash);
                    $article->setUpdatedDate(new \DateTime());

                    if (trim($this->getParam('summary')) == '') {
                        $article->setSummary($article->generateSummary());
                    }

                    $article->setSlug($article->generateSlug());

                    Event::trigger('before' . $this->articleType . 'Save', $article);

                    $content = $article->getTitle();
                    $content .= PHP_EOL . $article->getSummary();
                    $content .= PHP_EOL . $contentItem->getContent();

                    $data = ['model' => $article, 'content_id' => $article->getId(), 'content' => $content];
                    Event::trigger('ContentPublished', $data);

                    $article = $this->articleStore->save($article);

                    $this->successMessage($article->getTitle() . ' was edited successfully.', true);
                    header('Location: /' . $this->config->get('site.admin_uri') . '/' . $this->scope);
                } catch (Exception $e) {
                    $this->errorMessage(
                        'There was an error editing the ' . $this->lowerArticleType . '. Please try again.'
                    );
                }
            } else {
                $this->errorMessage(
                    'There was an error editing the ' . $this->lowerArticleType . '. Please try again.'
                );
            }

            $this->view->form = $form->render();
        } else {
            $article_data = $article->getDataArray();
            $article_data['content'] = json_decode($article->getContentItem()->getContent())->content;
            $form = $this->newsForm($article_data, 'edit');
            $this->view->form = $form->render();
        }
    }

    public function delete($newsId)
    {
        $article = $this->articleStore->getById($newsId);
        $this->articleStore->delete($article);
        $this->successMessage($article->getTitle() . ' was deleted successfully.', true);
        header('Location: /' . $this->config->get('site.admin_uri') . '/' . $this->scope);
    }

    public function newsForm($values = [], $type = 'add')
    {
        $form = new FormElement();
        $form->setMethod('POST');

        $adminUri = $this->config->get('site.admin_uri');
        if ($type == 'add') {
            $form->setAction('/' . $adminUri . '/' . $this->scope . '/add');
        } else {
            $form->setAction('/' . $adminUri . '/' . $this->scope . '/edit/' . $values['id']);
        }

        $form->setClass('smart-form');

        $fieldset = new Form\FieldSet('fieldset');
        $form->addField($fieldset);

        $field = new Form\Element\Text('title');
        $field->setRequired(true);
        $field->setLabel('Title');
        $fieldset->addField($field);

        $field = new Form\Element\Text('publish_date');
        $field->setLabel('Published Date');

        if (!isset($values['publish_date'])) {
            $values['publish_date'] = (new DateTime())->format('Y-m-d');
        } else {
            $values['publish_date'] = (new DateTime($values['publish_date']))->format('Y-m-d');
        }

        $field->setClass('sa-datepicker');
        $fieldset->addField($field);

        $field = new Form\Element\TextArea('summary');
        $field->setRequired(false);
        $field->setRows(5);
        $field->setLabel('Summary (optional)');
        $fieldset->addField($field);

        $field = new Form\Element\TextArea('content');
        $field->setRequired(true);
        $field->setLabel('Content');
        $field->setClass('ckeditor advanced');
        $fieldset->addField($field);

        $field = new Form\Element\Select('author_id');
        $field->setOptions($this->userStore->getNames());

        if (isset($values['user_id'])) {
            $field->setValue($values['user_id']);
        } else {
            $field->setValue($this->currentUser->getId());
        }
        $field->setClass('select2');
        $field->setLabel('Author');
        $fieldset->addField($field);

        if ($this->scope == "blog") {
            $field = new Form\Element\Text('guest_author_name');
            $field->setRequired(false);
            $field->setLabel('Guest Author Name');
            $fieldset->addField($field);

            $field = new Form\Element\Text('guest_company_name');
            $field->setRequired(false);
            $field->setLabel('Guest Company Name');
            $fieldset->addField($field);

            $field = new Form\Element\Text('guest_company_url');
            $field->setRequired(false);
            $field->setLabel('Guest Company Website URL');
            $fieldset->addField($field);
        }

        $field = new Form\Element\Select('category_id');
        $field->setOptions($this->categoryStore->getNamesForScope($this->scope));
        $field->setLabel('Category');
        $field->setClass('select2');
        $fieldset->addField($field);

        if ($this->scope == "news") {
            $field = new Form\Element\Select('use_in_email');
            $field->setOptions(['1'=>'Yes', '0'=>'No']);
            $field->setRequired(false);
            $field->setLabel('Publish in the email newsletter?');
            $fieldset->addField($field);
        }

        $field = new Form\Element\Text('image_id');
        $field->setClass('octo-image-picker');
        $field->setRequired(false);
        $field->setLabel('Image');
        $fieldset->addField($field);

        $data = [&$form, &$values];
        Event::trigger($this->scope . 'Form', $data);
        list($form, $values) = $data;

        $field = new Form\Element\Submit();
        $field->setValue('Save ' . $this->articleType);
        $field->setClass('btn-success');
        $form->addField($field);

        $form->setValues($values);
        return $form;
    }
}
