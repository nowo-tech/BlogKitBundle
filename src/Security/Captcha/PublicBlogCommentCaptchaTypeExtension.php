<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\Captcha;

use Nowo\BlogKitBundle\Form\PublicBlogCommentType;
use Nowo\BlogKitBundle\Security\BlogProtection;
use Override;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Attaches the active CAPTCHA strategy to the public comment form.
 */
final class PublicBlogCommentCaptchaTypeExtension extends AbstractTypeExtension
{
    public function __construct(
        private readonly BlogProtection $protection,
    ) {
    }

    /**
     * @return iterable<class-string>
     */
    #[Override]
    public static function getExtendedTypes(): iterable
    {
        return [PublicBlogCommentType::class];
    }

    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->protection->captcha()->configureForm($builder);
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('allow_extra_fields', true);
    }

    #[Override]
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $context = $this->protection->captcha()->twigContext();
        if ($context['strategy'] !== 'recaptcha_v3' || $context['site_key'] === '') {
            return;
        }

        $view->vars['attr']['data-blog-captcha']         = 'recaptcha_v3';
        $view->vars['attr']['data-blog-captcha-sitekey'] = $context['site_key'];
        $view->vars['attr']['data-blog-captcha-action']  = 'blog_comment';
    }
}
