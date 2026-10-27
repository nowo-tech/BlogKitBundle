<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Ui;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\AreaNav;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\Pagination;
use Nowo\UiKitBundle\View\SettingsPage;
use Nowo\UiKitBundle\View\SettingsSection;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_filter;
use function is_string;

/**
 * Builds the UiKit view models of the bundle pages (REQ-UI-003) from the controller data: page headers (with the blog
 * area nav as tabs), columns, row actions (POST + CSRF-only FormViews; destructive ones behind a Confirm), filters,
 * pagination and empty states. The controllers and the page contract test share it, so the test renders exactly the
 * view models the application gets. Stateless: safe in worker mode.
 */
final readonly class BlogKitPageFactory
{
    /** Comment status filters of the moderation queue, in toolbar order. */
    public const array COMMENT_STATUSES = ['pending', 'approved', 'rejected', 'all'];

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private BlogKitPages $pages = new BlogKitPages(),
        private ?BlogKitAreaNav $areaNav = null,
    ) {
    }

    /**
     * Template of a page: the `web_ui.templates.<page>` override, else the bundle template.
     */
    public function template(string $page, string $default): string
    {
        return $this->pages->template($page, $default);
    }

    /**
     * Admin page variables plus the shared ones (legacy layout bridge).
     *
     * @param array<string, mixed> $vars
     *
     * @return array<string, mixed>
     */
    public function context(array $vars): array
    {
        return $this->pages->context($vars);
    }

    /**
     * Public page variables plus the shared ones (page layout, legacy layout bridge).
     *
     * @param array<string, mixed> $vars
     *
     * @return array<string, mixed>
     */
    public function publicContext(array $vars): array
    {
        return $this->pages->publicContext($vars);
    }

    /**
     * Articles list.
     *
     * @param list<BlogArticle> $items
     * @param array{page: int, per_page: int, total: int, total_pages: int} $pagination
     * @param array<string, string> $filters active filter values
     * @param array<int, FormView> $deleteForms article id => CSRF-only delete form (articles the user may manage)
     */
    public function articleIndex(array $items, array $pagination, FormView $filterForm, array $filters, array $deleteForms): ListPage
    {
        $indexUrl  = $this->url('admin_blog_index');
        $newAction = Action::create($this->url('admin_blog_new'), BlogKitPages::label('admin.articles.new'));
        $actions   = [];
        foreach ($deleteForms as $id => $deleteForm) {
            $actions[$id] = [
                Action::edit($this->url('admin_blog_edit', ['id' => $id]), BlogKitPages::label('admin.action.edit')),
                $this->deleteAction($deleteForm, 'admin.confirm.delete_article'),
            ];
        }

        return new ListPage(
            header: new PageHeader(BlogKitPages::label('admin.articles.title'), actions: [$newAction], tabs: $this->areaNav($indexUrl)),
            columns: [
                new Column('position', BlogKitPages::label('admin.column.position'), align: 'end'),
                new Column('slug', BlogKitPages::label('admin.column.slug')),
                new Column('title', BlogKitPages::label('admin.column.title'), required: true),
                new Column('status', BlogKitPages::label('admin.column.status')),
                new Column('updated_at', BlogKitPages::label('admin.audit.updated_at'), priority: 2),
                new Column('updated_by', BlogKitPages::label('admin.audit.updated_by'), priority: 3),
            ],
            rows: $items,
            filters: $filterForm,
            rowActions: static fn (BlogArticle $row): array => $actions[$row->getId()] ?? [],
            pagination: new Pagination($pagination['page'], $pagination['per_page'], $pagination['total'], $pagination['total_pages'], 'admin_blog_index', $filters),
            empty: new EmptyState(null, BlogKitPages::label('admin.articles.empty'), $newAction),
            filtersClearHref: $filters !== [] ? $indexUrl : null,
            bundle: BlogKitPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'blog-kit-articles'],
            actionsLabel: BlogKitPages::label('admin.column.actions'),
        );
    }

    /**
     * Article create / edit form.
     */
    public function articleForm(FormView $form, string $title): FormPage
    {
        return $this->formPage($form, $title, 'admin_blog_index', 'blog-kit-article-form');
    }

    /**
     * Tags list.
     *
     * @param list<BlogTag> $items
     * @param array<string, string> $filters active filter values
     * @param array<int, FormView> $deleteForms tag id => CSRF-only delete form (tags the user may manage)
     */
    public function tagIndex(array $items, FormView $filterForm, array $filters, array $deleteForms): ListPage
    {
        $indexUrl  = $this->url('admin_blog_tags_index');
        $newAction = Action::create($this->url('admin_blog_tags_new'), BlogKitPages::label('admin.tags.new'));
        $actions   = [];
        foreach ($deleteForms as $id => $deleteForm) {
            $actions[$id] = [
                Action::edit($this->url('admin_blog_tags_edit', ['id' => $id]), BlogKitPages::label('admin.action.edit')),
                $this->deleteAction($deleteForm, 'admin.confirm.delete_tag'),
            ];
        }

        return new ListPage(
            header: new PageHeader(BlogKitPages::label('admin.tags.title'), BlogKitPages::label('admin.tags.lead'), [$newAction], tabs: $this->areaNav($indexUrl)),
            columns: [
                new Column('slug', BlogKitPages::label('admin.column.slug')),
                new Column('name', BlogKitPages::label('admin.column.name'), required: true),
                new Column('articles', BlogKitPages::label('admin.column.articles'), align: 'end'),
            ],
            rows: $items,
            filters: $filterForm,
            rowActions: static fn (BlogTag $row): array => $actions[$row->getId()] ?? [],
            empty: new EmptyState(null, BlogKitPages::label('admin.tags.empty'), $newAction),
            filtersClearHref: $filters !== [] ? $indexUrl : null,
            bundle: BlogKitPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'blog-kit-tags'],
            actionsLabel: BlogKitPages::label('admin.column.actions'),
        );
    }

    /**
     * Tag create / edit form.
     */
    public function tagForm(FormView $form, string $title): FormPage
    {
        return $this->formPage($form, $title, 'admin_blog_tags_index', 'blog-kit-tag-form');
    }

    /**
     * Comment moderation queue.
     *
     * @param list<BlogComment> $items
     * @param array<string, string> $filters active filter values
     * @param array<int, array{approve: FormView, reject: FormView, delete: FormView}> $actionForms comment id => CSRF-only forms (comments the user may moderate)
     */
    public function commentIndex(array $items, FormView $filterForm, array $filters, string $status, int $pendingCount, array $actionForms): ListPage
    {
        $byId = [];
        foreach ($items as $item) {
            $byId[(int) $item->getId()] = $item;
        }

        $actions = [];
        foreach ($actionForms as $id => $forms) {
            $rowActions = [];
            if (($byId[$id] ?? null)?->getStatus() === BlogCommentStatus::Pending) {
                $rowActions[] = $this->postAction($forms['approve'], 'admin.action.approve', 'save', 'primary');
                $rowActions[] = $this->postAction($forms['reject'], 'admin.action.reject', 'cancel', 'secondary');
            }
            $rowActions[] = $this->deleteAction($forms['delete'], 'admin.confirm.delete_comment');
            $actions[$id] = $rowActions;
        }

        $statusLinks = [];
        foreach (self::COMMENT_STATUSES as $value) {
            $current       = $value === $status;
            $statusLinks[] = new Action(
                'custom',
                BlogKitPages::label('admin.status.' . $value),
                $this->url('admin_blog_comments_index', ['status' => $value] + $filters),
                variant: $current ? 'primary' : 'secondary',
                attributes: ['data-testid' => 'blog-kit-comments-status-' . $value] + ($current ? ['aria-current' => 'page'] : []),
                display: 'text',
            );
        }

        return new ListPage(
            header: new PageHeader(
                BlogKitPages::label('admin.comments.title'),
                $pendingCount > 0 ? BlogKitPages::label('admin.comments.pending_count', ['%count%' => $pendingCount]) : null,
                $statusLinks,
                tabs: $this->areaNav($this->url('admin_blog_comments_index')),
            ),
            columns: [
                new Column('date', BlogKitPages::label('admin.column.date')),
                new Column('article', BlogKitPages::label('admin.column.article'), priority: 2),
                new Column('author', BlogKitPages::label('admin.column.author')),
                new Column('comment', BlogKitPages::label('admin.column.comment'), required: true),
                new Column('status', BlogKitPages::label('admin.column.status')),
                new Column('reply', BlogKitPages::label('admin.column.reply'), priority: 3),
            ],
            rows: $items,
            filters: $filterForm,
            rowActions: static fn (BlogComment $row): array => $actions[$row->getId()] ?? [],
            empty: new EmptyState(null, BlogKitPages::label('admin.comments.empty')),
            filtersClearHref: $filters !== [] ? $this->url('admin_blog_comments_index', ['status' => $status]) : null,
            bundle: BlogKitPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'blog-kit-comments'],
            actionsLabel: BlogKitPages::label('admin.column.actions'),
        );
    }

    /**
     * Settings page: one section per settings screen (tabs), the current one with its form.
     */
    public function settings(string $section, FormView $form): SettingsPage
    {
        $sections = [];
        foreach (BlogKitAreaNav::SETTINGS_SECTIONS as $id => $route) {
            $current    = $id === $section;
            $sections[] = new SettingsSection(
                $id,
                BlogKitPages::label(BlogKitAreaNav::SETTINGS_LABELS[$id]),
                $this->url($route),
                $current ? $form : null,
                $current ? BlogKitPages::label(BlogKitAreaNav::SETTINGS_LABELS[$id] . '_lead') : null,
                BlogKitPages::label('admin.action.save'),
            );
        }

        return new SettingsPage(
            header: new PageHeader(BlogKitPages::label('admin.settings.title'), BlogKitPages::label('admin.settings.lead'), tabs: $this->areaNav($this->url('admin_blog_settings'))),
            sections: $sections,
            current: $section,
            bundle: BlogKitPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'blog-kit-settings'],
        );
    }

    /**
     * Page header of the public blog index.
     */
    public function publicIndexHeader(): PageHeader
    {
        return new PageHeader(BlogKitPages::label('page.blog.heading'), BlogKitPages::label('page.blog.intro'), breadcrumbsMode: 'own');
    }

    /**
     * Page header of a public article: its title and excerpt.
     */
    public function publicArticleHeader(string $title, string $excerpt): PageHeader
    {
        return new PageHeader($title, $excerpt !== '' ? $excerpt : null, breadcrumbsMode: 'own');
    }

    /**
     * Pagination of the public blog index (query = the active search / tag filters).
     *
     * @param array{page: int, per_page: int, total: int, total_pages: int} $pagination
     * @param array<string, string> $filters
     */
    public function publicPager(array $pagination, array $filters): Pagination
    {
        return new Pagination(
            $pagination['page'],
            $pagination['per_page'],
            $pagination['total'],
            $pagination['total_pages'],
            'blog_index',
            array_filter($filters, static fn (string $value): bool => $value !== ''),
        );
    }

    private function formPage(FormView $form, string $title, string $indexRoute, string $testId): FormPage
    {
        $indexUrl = $this->url($indexRoute);

        return new FormPage(
            header: new PageHeader(BlogKitPages::label($title), back: new BackLink($indexUrl, BlogKitPages::label('admin.action.back'), 'blog-kit-back')),
            form: $form,
            submitLabel: BlogKitPages::label('admin.action.save'),
            cancelHref: $indexUrl,
            cancelLabel: BlogKitPages::label('admin.action.cancel'),
            bundle: BlogKitPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => $testId],
        );
    }

    private function deleteAction(FormView $form, string $message): Action
    {
        return Action::delete(
            $this->formAction($form),
            null,
            new Confirm(BlogKitPages::label('admin.confirm.delete_title'), BlogKitPages::label($message), BlogKitPages::label('admin.action.delete')),
            BlogKitPages::label('admin.action.delete'),
            $form,
        );
    }

    private function postAction(FormView $form, string $label, string $icon, string $variant): Action
    {
        return new Action('custom', BlogKitPages::label($label), $this->formAction($form), method: 'POST', icon: $icon, variant: $variant, form: $form);
    }

    private function formAction(FormView $form): string
    {
        $action = $form->vars['action'] ?? '';

        return is_string($action) ? $action : '';
    }

    private function areaNav(string $current): ?AreaNav
    {
        return $this->areaNav?->areaNav(BlogKitAreaNav::AREA, ['current' => $current]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function url(string $route, array $params = []): string
    {
        return $this->urlGenerator->generate($route, $params);
    }
}
