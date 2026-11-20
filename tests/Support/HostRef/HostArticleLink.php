<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support\HostRef;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogTag;

/**
 * An entity of the host application pointing at blog entities (audits XD2-1, XD3-1): a field of its own, a to-one
 * association to an article and a collection of tags.
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_ref_article_link')]
class HostArticleLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 50)]
    public string $name = 'link';

    /** @var Collection<int, BlogTag> */
    #[ORM\ManyToMany(targetEntity: BlogTag::class)]
    #[ORM\JoinTable(name: 'host_ref_article_link_tag')]
    public Collection $tags;

    public function __construct(#[ORM\ManyToOne(targetEntity: BlogArticle::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public ?BlogArticle $article = null)
    {
        $this->tags = new ArrayCollection();
    }
}
