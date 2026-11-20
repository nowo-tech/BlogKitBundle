<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Form;

use Override;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

use function bin2hex;
use function random_bytes;

/**
 * Public blog comment submission form.
 *
 * @extends AbstractBlogFormType<array<string, mixed>>
 */
final class PublicBlogCommentType extends AbstractBlogFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function (): void {
            $this->addTextField('authorName', [
                'placeholder' => 'public_blog_comment.author_name.placeholder',
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 120),
                ],
            ]);
            $this->addEmailField('authorEmail', [
                'placeholder' => 'public_blog_comment.author_email.placeholder',
                'constraints' => [
                    new NotBlank(),
                    new Email(),
                    new Length(max: 180),
                ],
            ]);
            $this->addTextareaField('body', [
                'attr'        => ['rows' => 5],
                'placeholder' => 'public_blog_comment.body.placeholder',
                'constraints' => [
                    new NotBlank(),
                    new Length(min: 3, max: 5000),
                ],
            ]);
            $this->addCheckboxField('privacyAccepted', [
                'mapped'      => false,
                'required'    => true,
                'help'        => false,
                'constraints' => [
                    new IsTrue(message: 'blog.comments.privacy_required'),
                ],
            ]);
        });
        // One token per rendered form (1.11, REQ-RUNTIME-003 rule 4): the same form posted twice stores one comment.
        $builder->add('submitToken', HiddenType::class, ['mapped' => false, 'required' => false, 'data' => bin2hex(random_bytes(16)), 'empty_data' => '']);
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
    }
}
