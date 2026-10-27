<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Contract;

use Nowo\BlogKitBundle\Tests\Support\PageFixtures;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;

/**
 * REQ-UI-003 page contract: the admin pages extend UiKit scaffolds (list, form, settings), the public index and article
 * are declared unique pages on the guest layout; no <html>, CDN, inline handler or confirm(); framework classes only
 * through theme slots; the stub theme is reflected; no raw or missing translation keys (strict rule); the CSRF forms
 * of the row actions keep their hidden fields.
 */
final class PageContractTest extends PageContractTestCase
{
    use BlogKitContractEnvironment;

    public static function pageFixtures(): iterable
    {
        yield 'articles' => new PageFixture('@NowoBlogKitBundle/admin/index.html.twig', PageFixtures::articleIndex());
        yield 'articles (empty, filtered)' => new PageFixture('@NowoBlogKitBundle/admin/index.html.twig', PageFixtures::articleIndex(true));
        yield 'article new' => new PageFixture('@NowoBlogKitBundle/admin/form.html.twig', PageFixtures::articleForm());
        yield 'article edit' => new PageFixture('@NowoBlogKitBundle/admin/form.html.twig', PageFixtures::articleForm(true));
        yield 'tags' => new PageFixture('@NowoBlogKitBundle/admin/tags/index.html.twig', PageFixtures::tagIndex());
        yield 'tags (empty)' => new PageFixture('@NowoBlogKitBundle/admin/tags/index.html.twig', PageFixtures::tagIndex(true));
        yield 'tag edit' => new PageFixture('@NowoBlogKitBundle/admin/tags/form.html.twig', PageFixtures::tagForm());
        yield 'comments' => new PageFixture('@NowoBlogKitBundle/admin/comments/index.html.twig', PageFixtures::commentIndex());
        yield 'comments (empty)' => new PageFixture('@NowoBlogKitBundle/admin/comments/index.html.twig', PageFixtures::commentIndex(true));
        foreach (['listing', 'cards', 'index-aside', 'article', 'comments'] as $section) {
            yield 'settings ' . $section => new PageFixture('@NowoBlogKitBundle/admin/settings.html.twig', PageFixtures::settings($section));
        }
        yield 'public index' => new PageFixture('@NowoBlogKitBundle/public/index.html.twig', PageFixtures::publicIndex());
        yield 'public index (empty)' => new PageFixture('@NowoBlogKitBundle/public/index.html.twig', PageFixtures::publicIndex(true));
        yield 'public article' => new PageFixture('@NowoBlogKitBundle/public/show.html.twig', PageFixtures::publicShow());
    }
}
