<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Form;

use Override;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

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
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 120),
                ],
            ]);
            $this->addEmailField('authorEmail', [
                'constraints' => [
                    new NotBlank(),
                    new Email(),
                    new Length(max: 180),
                ],
            ]);
            $this->addTextareaField('body', [
                'attr'        => ['rows' => 5],
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
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
    }
}
