<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\Captcha;

use Override;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

use function is_string;

/**
 * Hidden extra field that bots tend to fill (`honeypot` strategy).
 */
final readonly class HoneypotCommentCaptchaStrategy implements BlogCommentCaptchaStrategyInterface
{
    public function __construct(
        private string $fieldName = 'website',
    ) {
    }

    #[Override]
    public function configureForm(FormBuilderInterface $builder): void
    {
        $name = $this->fieldName !== '' ? $this->fieldName : 'website';

        $builder->add($name, TextType::class, [
            'mapped'   => false,
            'required' => false,
            'label'    => false,
            'attr'     => [
                'autocomplete' => 'off',
                'tabindex'     => '-1',
                'aria-hidden'  => 'true',
                'class'        => 'blog-comment-hp__input',
            ],
            'row_attr' => [
                'class'       => 'blog-comment-hp',
                'aria-hidden' => 'true',
            ],
        ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event) use ($name): void {
            $form  = $event->getForm();
            $value = $form->has($name) ? $form->get($name)->getData() : null;
            if (is_string($value) && trim($value) !== '') {
                $form->addError(new FormError('blog.comments.error_invalid'));
            }
        });
    }

    #[Override]
    public function twigContext(): array
    {
        return [
            'enabled'      => true,
            'strategy'     => 'honeypot',
            'site_key'     => '',
            'script_url'   => '',
            'widget_class' => '',
        ];
    }
}
