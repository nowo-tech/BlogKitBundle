<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogArticleResource;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Entity\BlogTagTranslation;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlogArticleEntityTest extends TestCase
{
    #[Test]
    public function testAccessorsTranslationsTagsAndResources(): void
    {
        $article = new BlogArticle();
        $article->setSlug('hello-world')
            ->setImage('/img.jpg')
            ->setPublishedAt(new DateTimeImmutable('2024-01-01'))
            ->setLinkedinUrl('https://linkedin.com/x')
            ->setPosition(3)
            ->setPublished(false);

        $es = new BlogArticleTranslation();
        $es->setLocale('es')->setTitle('Hola')->setExcerpt('ex')->setBody('body')->setMetaTitle('mt')->setMetaDescription('md');
        $article->addTranslation($es);

        $tag = new BlogTag();
        $tag->setSlug('php');
        $tag->addTranslation((new BlogTagTranslation())->setLocale('es')->setName('PHP'));
        self::assertSame('php', (string) $tag);
        $article->addTag($tag)->addTag($tag);
        self::assertCount(1, $article->getTags());
        $article->setTags([$tag]);
        self::assertSame([$tag], $article->getTagsSorted());
        $article->removeTag($tag);
        self::assertCount(0, $article->getTags());
        $article->addTag($tag);

        $resource = (new BlogArticleResource())->setTitle('PDF')->setImage('/a.pdf')->setPosition(1);
        $article->addResource($resource)->addResource($resource);
        self::assertCount(1, $article->getResources());
        self::assertSame($article, $resource->getArticle());
        self::assertSame(['id' => null, 'title' => 'PDF', 'image' => '/a.pdf', 'position' => 1], $resource->toArray());
        $article->removeResource($resource);
        self::assertCount(0, $article->getResources());
        $article->addResource($resource);

        self::assertNull($article->getId());
        self::assertSame('hello-world', $article->getSlug());
        self::assertSame('/img.jpg', $article->getImage());
        self::assertSame('https://linkedin.com/x', $article->getLinkedinUrl());
        self::assertSame(3, $article->getPosition());
        self::assertFalse($article->isPublished());
        self::assertSame($es, $article->getTranslation('es'));
        self::assertNull($article->getTranslation('en'));
        self::assertSame('Hola', $article->getTitle('es'));
        self::assertSame($es, $article->getTranslationOrFallback('es'));

        $payload = $article->toArray('es');
        self::assertSame('hello-world', $payload['slug']);
        self::assertSame('Hola', $payload['title']);
        self::assertSame('2024-01-01', $payload['published_at']);
        self::assertSame([['slug' => 'php', 'name' => 'PHP']], $payload['tags']);
    }

    #[Test]
    public function testFallbackCreatesEmptyTranslation(): void
    {
        $article = new BlogArticle();
        $en      = new BlogArticleTranslation();
        $en->setLocale('en')->setTitle('Hi');
        $article->addTranslation($en);
        // First matching translation is used when the requested locale is missing.
        self::assertSame($en, $article->getTranslationOrFallback('en'));
        self::assertSame('Hi', $article->getTitle('en'));
    }

    #[Test]
    public function testBlogTagFallbacksAndAccessors(): void
    {
        LocaleTestSupport::bindDefaults();

        // getTranslationOrFallback: only 'en' translation → ask for 'fr' → falls back to 'en'
        $tag = new BlogTag();
        $tag->setSlug('design');
        $enTr = (new BlogTagTranslation())->setLocale('en')->setName('Design');
        $tag->addTranslation($enTr);

        $fallback = $tag->getTranslationOrFallback('fr');
        self::assertSame('Design', $fallback->getName());

        // No translations at all → returns empty BlogTagTranslation
        $tag2  = new BlogTag();
        $empty = $tag2->getTranslationOrFallback('es');
        self::assertInstanceOf(BlogTagTranslation::class, $empty);
        self::assertSame('', $empty->getName());

        // getId() returns null on new entity; getArticles() is empty collection
        self::assertNull($tag->getId());
        self::assertCount(0, $tag->getArticles());
        self::assertNotNull($tag->getTranslations());

        // addTranslation is idempotent
        $countBefore = $tag->getTranslations()->count();
        $tag->addTranslation($enTr);
        self::assertSame($countBefore, $tag->getTranslations()->count());
    }
}
