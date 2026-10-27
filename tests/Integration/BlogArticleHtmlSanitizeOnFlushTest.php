<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration;

use Doctrine\ORM\Events;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Enum\HtmlSanitizeStrategy;
use Nowo\BlogKitBundle\EventSubscriber\BlogArticleHtmlSanitizeSubscriber;
use Nowo\BlogKitBundle\Tests\Support\BlogProtectionTestFactory;
use PHPUnit\Framework\Attributes\Test;

/**
 * SEC-004 L4: the sanitizer runs on persist AND on update, and the update result is what Doctrine writes.
 */
final class BlogArticleHtmlSanitizeOnFlushTest extends DoctrineTestCase
{
    #[Test]
    public function updatedBodiesAreStoredSanitized(): void
    {
        $this->entityManager->getEventManager()->addEventListener(
            [Events::prePersist, Events::preUpdate],
            new BlogArticleHtmlSanitizeSubscriber(BlogProtectionTestFactory::create(['htmlStrategy' => HtmlSanitizeStrategy::Allowlist])),
        );

        $article     = $this->createArticle('xss-update', bodyEs: '<p>Safe<script>alert(1)</script></p>');
        $translation = $article->getTranslation('es');
        self::assertInstanceOf(BlogArticleTranslation::class, $translation);
        $id = $translation->getId();
        self::assertStringNotContainsString('<script', (string) $this->storedBody($id));

        $translation->setBody('<p onclick="alert(2)">Edited<img src=x onerror=alert(3)></p>');
        $this->entityManager->flush();

        $stored = (string) $this->storedBody($id);
        self::assertStringContainsString('Edited', $stored);
        self::assertStringNotContainsString('onclick', $stored);
        self::assertStringNotContainsString('onerror', $stored);
    }

    private function storedBody(?int $id): mixed
    {
        return $this->connection->fetchOne('SELECT body FROM content_blog_article_translation WHERE id = ?', [$id]);
    }
}
