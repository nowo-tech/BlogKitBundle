<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\DependencyInjection;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\BlogKitBundle\DependencyInjection\TablePrefixListener;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TablePrefixListenerTest extends TestCase
{
    #[Test]
    public function emptyPrefixIsANoOp(): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects(self::never())->method('setPrimaryTable');

        $event = $this->createMock(LoadClassMetadataEventArgs::class);
        $event->method('getClassMetadata')->willReturn($metadata);

        (new TablePrefixListener(''))->loadClassMetadata($event);
    }

    #[Test]
    public function nonBlogKitEntityIsANoOp(): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn('App\\Entity\\Post');
        $metadata->expects(self::never())->method('setPrimaryTable');

        $event = $this->createMock(LoadClassMetadataEventArgs::class);
        $event->method('getClassMetadata')->willReturn($metadata);

        (new TablePrefixListener('bk_'))->loadClassMetadata($event);
    }

    #[Test]
    public function blogKitEntityTableNameIsPrefixed(): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn(BlogArticle::class);
        $metadata->method('getTableName')->willReturn('content_blog_article');
        $metadata->expects(self::once())
            ->method('setPrimaryTable')
            ->with(['name' => 'bk_content_blog_article']);

        $event = $this->createMock(LoadClassMetadataEventArgs::class);
        $event->method('getClassMetadata')->willReturn($metadata);

        (new TablePrefixListener('bk_'))->loadClassMetadata($event);
    }
}
