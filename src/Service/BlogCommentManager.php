<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;
use Nowo\BlogKitBundle\Model\BlogStaffDisplayNameInterface;
use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Nowo\BlogKitBundle\Repository\BlogCommentRepository;
use Nowo\BlogKitBundle\Transaction\BlogKitTransactionRunner;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

use function hash;
use function preg_match;
use function sprintf;

/**
 * Creates and moderates blog comments.
 *
 * Moderation timestamps come from the injected PSR-20 clock (REQ-DI-001). Moderation actions are
 * logged with the comment id only — never the author name, e-mail, body or IP (REQ-OBS-001).
 *
 * Since 1.10.0 (REQ-RUNTIME-003): each method is one unit of {@see BlogKitTransactionRunner} (the entities are built
 * and changed inside it, so the host's manager is clean when it starts); a lock conflict leaves as
 * TransientStorageFailure (409 + CONFLICT_001 on the bundle's routes). Logs follow the commit.
 */
final readonly class BlogCommentManager
{
    private BlogKitTransactionRunner $transactionRunner;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private BlogCommentRepository $blogCommentRepository,
        private ClockInterface $clock = new NativeClock(),
        private LoggerInterface $logger = new NullLogger(),
        #[Autowire('%nowo_blog_kit.comments.staff_name%')]
        private string $staffName = 'Team',
        ?BlogKitTransactionRunner $transactionRunner = null,
    ) {
        $this->transactionRunner = $transactionRunner ?? BlogKitTransactionRunner::unmanaged($entityManager);
    }

    /**
     * Creates the pending comment of a public submission. With the one-time `$submitToken` of the rendered form
     * (1.11, REQ-RUNTIME-003 rule 4) a form posted twice stores one comment: the idempotency key is a hash of the token,
     * the article, the email and the text, unique in the table, and the second submission (also a concurrent one: the
     * unit runs once more after the unique violation and finds the winner) returns the stored comment. Another
     * visitor on a cached page with the same token has another email or text, so another key.
     */
    public function createPublicComment(
        BlogArticle $blogArticle,
        string $authorName,
        string $authorEmail,
        string $body,
        Request $request,
        ?string $submitToken = null,
    ): BlogComment {
        $ipHash    = $this->hashIp($request);
        $submitKey = $this->submitKey($submitToken, $blogArticle, trim($authorEmail), trim($body));
        $articleId = $blogArticle->getId();

        // True once the unit has run: the second run is the replay after a unique violation.
        $replay = false;

        // @igor-ignore - The closure runs at once inside the unit; the runner keeps no reference to it.
        return $this->transactionRunner->runRereadingOnDuplicate(function () use ($blogArticle, $articleId, $authorName, $authorEmail, $body, $ipHash, $submitKey, &$replay): BlogComment {
            if ($submitKey !== null) {
                // The first look is a plain read: a locking read of a missing key takes a gap lock, and two concurrent
                // submissions would then deadlock on their inserts. The replay (the key exists now) is a locking read: a
                // winner that committed after this transaction's snapshot (REPEATABLE READ) is still found.
                $stored = $this->blogCommentRepository->findOneBySubmitKey($submitKey, $replay);
                if ($stored instanceof BlogComment) {
                    return $stored;
                }
            }
            $replay = true;

            // The replay runs on a replaced manager: the article captured before the failure is detached, so the
            // comment points at a reference of the same row in the current unit of work.
            $article = $articleId === null ? $blogArticle : $this->entityManager->getReference(BlogArticle::class, $articleId);

            $blogComment = new BlogComment()
                ->setArticle($article instanceof BlogArticle ? $article : $blogArticle)
                ->setAuthorName(trim($authorName))
                ->setAuthorEmail(trim($authorEmail))
                ->setBody(trim($body))
                ->setStatus(BlogCommentStatus::Pending)
                ->setIpHash($ipHash)
                ->setSubmitKey($submitKey);

            $this->entityManager->persist($blogComment);

            return $blogComment;
        });
    }

    /**
     * Idempotency key of a submission, null without a well-formed token (a form rendered before 1.11, host code).
     */
    private function submitKey(?string $token, BlogArticle $blogArticle, string $email, string $body): ?string
    {
        if ($token === null || preg_match('/^[A-Za-z0-9]{16,64}$/', $token) !== 1) {
            return null;
        }

        return hash('sha256', $token . "\0" . ($blogArticle->getId() ?? 0) . "\0" . $email . "\0" . $body);
    }

    /**
     * Since 1.10.0 `$approvePendingParent`: approve the parent in the same unit when it is still pending (one
     * transaction for the reply and the approval: a conflict never leaves the reply saved and the parent pending).
     */
    public function createStaffReply(
        BlogComment $blogComment,
        BlogUserInterface $user,
        string $body,
        bool $approvePendingParent = false,
    ): BlogComment {
        $approved = false;
        // @igor-ignore - The closure runs at once inside the unit; the runner keeps no reference to it.
        $reply = $this->transactionRunner->run(function () use ($blogComment, $user, $body, $approvePendingParent, &$approved): BlogComment {
            $blogComment = $this->own($blogComment);
            $reply       = new BlogComment()
                ->setArticle($blogComment->getArticle())
                ->setParent($blogComment)
                ->setAuthorName($this->staffDisplayName($user))
                ->setStaffAuthor($user)
                ->setBody(trim($body))
                ->setStatus(BlogCommentStatus::Approved)
                ->setModeratedAt($this->clock->now())
                ->setModeratedBy($user);

            $this->entityManager->persist($reply);

            $approved = $approvePendingParent && $blogComment->getStatus() === BlogCommentStatus::Pending;
            if ($approved) {
                $this->moderate($blogComment, BlogCommentStatus::Approved, $user);
            }

            return $reply;
        });

        if ($approved) {
            $this->logModeration('comment_approved', $blogComment);
        }

        return $reply;
    }

    public function approve(BlogComment $blogComment, BlogUserInterface $user): void
    {
        // @igor-ignore - The closure runs at once inside the unit; the runner keeps no reference to it.
        $this->transactionRunner->run(function () use ($blogComment, $user): void {
            $this->moderate($this->own($blogComment), BlogCommentStatus::Approved, $user);
        });
        $this->logModeration('comment_approved', $blogComment);
    }

    public function reject(BlogComment $blogComment, BlogUserInterface $user): void
    {
        // @igor-ignore - The closure runs at once inside the unit; the runner keeps no reference to it.
        $this->transactionRunner->run(function () use ($blogComment, $user): void {
            $this->moderate($this->own($blogComment), BlogCommentStatus::Rejected, $user);
        });
        $this->logModeration('comment_rejected', $blogComment);
    }

    public function delete(BlogComment $blogComment): void
    {
        $commentId = $blogComment->getId();
        // @igor-ignore - The closure runs at once inside the unit; the runner keeps no reference to it.
        $this->transactionRunner->run(function () use ($blogComment): void {
            $this->entityManager->remove($this->own($blogComment));
        });
        $this->logger->info('Blog comment deleted.', ['bundle' => 'blog_kit', 'action' => 'comment_deleted', 'comment_id' => $commentId]);
    }

    /** @return list<BlogComment> */
    public function approvedCommentsForArticle(BlogArticle $blogArticle): array
    {
        return $this->blogCommentRepository->findApprovedRootCommentsByArticle($blogArticle);
    }

    /**
     * Since 2.0 (REQ-ARCH-001 rule 6, lesson W1-2): the comment as the bundle's private manager holds it. A comment your
     * own manager loaded (`$yourManager->find(BlogComment::class, $id)`, a host query) is not the instance the private
     * manager writes: changing it there would be saved by nobody (1.x flushed your manager), so it is re-loaded by id,
     * never inserted again. A new comment (no id) and the dedicated `doctrine.entity_manager` are left as they are.
     *
     * @throws LogicException when the row no longer exists
     */
    private function own(BlogComment $blogComment): BlogComment
    {
        $id = $blogComment->getId();
        if (!$this->entityManager instanceof PrivateEntityManager || $id === null || $this->entityManager->contains($blogComment)) {
            return $blogComment;
        }

        return $this->entityManager->find(BlogComment::class, $id)
            ?? throw new LogicException(sprintf('The blog comment #%d no longer exists.', $id));
    }

    private function moderate(BlogComment $blogComment, BlogCommentStatus $status, BlogUserInterface $user): void
    {
        $blogComment
            ->setStatus($status)
            ->setModeratedAt($this->clock->now())
            ->setModeratedBy($user);
    }

    private function logModeration(string $action, BlogComment $blogComment): void
    {
        $this->logger->info('Blog comment moderated.', ['bundle' => 'blog_kit', 'action' => $action, 'comment_id' => $blogComment->getId()]);
    }

    /**
     * Public author name of a staff reply: the user's {@see BlogStaffDisplayNameInterface} name, else the configured
     * `comments.staff_name`. The login identifier (often an e-mail) is never published (SEC-004 I5).
     */
    private function staffDisplayName(BlogUserInterface $user): string
    {
        $name = $user instanceof BlogStaffDisplayNameInterface ? trim($user->getBlogDisplayName()) : '';

        return $name !== '' ? $name : $this->staffName;
    }

    private function hashIp(Request $request): ?string
    {
        $ip = $request->getClientIp();

        if ($ip === null || $ip === '') {
            return null;
        }

        return hash('sha256', $ip);
    }
}
