<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Form;

use Override;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Staff reply form for blog comment moderation.
 *
 * @extends AbstractBlogFormType<array<string, mixed>>
 */
final class StaffBlogCommentReplyType extends AbstractBlogFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function (): void {
            $this->addTextareaField('body', [
                'attr'        => ['rows' => 4],
                'constraints' => [
                    new NotBlank(),
                    new Length(min: 1, max: 5000),
                ],
            ]);
        });
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults([
            'allow_extra_fields' => true,
        ]);
    }
}
