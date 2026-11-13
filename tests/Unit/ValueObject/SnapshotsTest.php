<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\ValueObject;

use DateTimeImmutable;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Entity\BlogTagTranslation;
use Nowo\BlogKitBundle\Tests\Support\TestUser;
use Nowo\BlogKitBundle\ValueObject\BlogArticleSnapshot;
use Nowo\BlogKitBundle\ValueObject\BlogCommentCaptchaWidget;
use Nowo\BlogKitBundle\ValueObject\BlogCommentRateLimitDecision;
use Nowo\BlogKitBundle\ValueObject\BlogCommentRequestContext;
use Nowo\BlogKitBundle\ValueObject\BlogCommentSnapshot;
use Nowo\BlogKitBundle\ValueObject\BlogTagSnapshot;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SnapshotsTest extends TestCase
{
    #[Test]
    public function articleSnapshotCopiesValuesNeverEntities(): void
    {
        $tag     = new BlogTag()->setSlug('php');
        $article = new BlogArticle()->setId(5)->setSlug('hello')->setPublished(true)->setPosition(3)
            ->setPublishedAt(new DateTimeImmutable('2026-01-01'));
        $article->addTranslation(new BlogArticleTranslation()->setLocale('en')->setTitle('Hello'));
        $article->addTranslation(new BlogArticleTranslation()->setLocale('es')->setTitle(''));
        $article->addTag($tag);
        $article->setCreatedBy(new TestUser()->setId(9));
        $article->setUpdatedBy(new BlogUserRef('editor'));

        $snapshot = BlogArticleSnapshot::of($article);

        self::assertSame(5, $snapshot->id);
        self::assertSame('hello', $snapshot->slug);
        self::assertTrue($snapshot->published);
        self::assertSame(3, $snapshot->position);
        self::assertSame('2026-01-01', $snapshot->publishedAt?->format('Y-m-d'));
        self::assertSame('9', $snapshot->createdById);
        self::assertSame('editor', $snapshot->updatedById);
        self::assertSame(['en' => 'Hello'], $snapshot->titles);
        self::assertSame(['php'], $snapshot->tagSlugs);
    }

    #[Test]
    public function tagAndCommentSnapshots(): void
    {
        $tag = new BlogTag()->setId(2)->setSlug('php');
        $tag->addTranslation(new BlogTagTranslation()->setLocale('en')->setName('PHP'));
        self::assertEquals(new BlogTagSnapshot(2, 'php', ['en' => 'PHP']), BlogTagSnapshot::of($tag));

        $article = new BlogArticle()->setId(5)->setSlug('hello');
        $parent  = new BlogComment()->setId(1)->setArticle($article);
        $reply   = new BlogComment()->setId(2)->setArticle($article)->setParent($parent)
            ->setStatus(BlogCommentStatus::Approved)->setStaffAuthor(new BlogUserRef('staff'))->setModeratedBy(new BlogUserRef('mod'));

        $snapshot = BlogCommentSnapshot::of($reply);
        self::assertSame(2, $snapshot->id);
        self::assertSame(5, $snapshot->articleId);
        self::assertSame('hello', $snapshot->articleSlug);
        self::assertSame(1, $snapshot->parentId);
        self::assertSame('approved', $snapshot->status);
        self::assertTrue($snapshot->staffReply);
        self::assertSame('staff', $snapshot->staffAuthorId);
        self::assertSame('mod', $snapshot->moderatedById);

        $root = BlogCommentSnapshot::of($parent);
        self::assertNull($root->parentId);
        self::assertFalse($root->staffReply);
        self::assertNull($root->staffAuthorId);
    }

    #[Test]
    public function requestContextDecisionAndWidget(): void
    {
        $context = new BlogCommentRequestContext('1.2.3.4', 'example.test', 'POST', '/blog/x/comments', ['a' => 'b']);
        self::assertSame('b', $context->field('a'));
        self::assertSame('', $context->field('missing'));

        $allow = BlogCommentRateLimitDecision::allow();
        self::assertTrue($allow->allowed);
        $deny = BlogCommentRateLimitDecision::deny(-5, 'slow down');
        self::assertFalse($deny->allowed);
        self::assertSame(0, $deny->retryAfterSeconds);
        self::assertSame('slow down', $deny->reason);
        self::assertSame(30, BlogCommentRateLimitDecision::deny(30)->retryAfterSeconds);

        self::assertSame(
            ['enabled' => true, 'strategy' => 'turnstile', 'site_key' => 'k', 'script_url' => 'u', 'widget_class' => 'w'],
            new BlogCommentCaptchaWidget(true, 'turnstile', 'k', 'u', 'w')->toArray(),
        );
    }
}
