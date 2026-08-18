/**
 * Invisible reCAPTCHA v3 submit hook for the public comment form.
 *
 * @param root Search root.
 * @returns Disconnect callback.
 */
export function bindCommentCaptcha(root: ParentNode = document): () => void {
    const form = root.querySelector<HTMLFormElement>('form[data-blog-captcha="recaptcha_v3"]');
    if (!(form instanceof HTMLFormElement)) {
        return () => undefined;
    }

    const siteKey = form.dataset.blogCaptchaSitekey || '';
    if (siteKey === '') {
        return () => undefined;
    }

    const onSubmit = (event: Event): void => {
        if (form.dataset.blogCaptchaDone === '1') {
            return;
        }

        event.preventDefault();
        const grecaptcha = window.grecaptcha;
        if (grecaptcha === undefined) {
            form.dataset.blogCaptchaDone = '1';
            form.submit();
            return;
        }

        grecaptcha.ready(() => {
            void grecaptcha
                .execute(siteKey, { action: form.dataset.blogCaptchaAction || 'blog_comment' })
                .then((token) => {
                    let input = form.querySelector<HTMLInputElement>('input[name="g-recaptcha-response"]');
                    if (!(input instanceof HTMLInputElement)) {
                        input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'g-recaptcha-response';
                        form.appendChild(input);
                    }
                    input.value = token;
                    form.dataset.blogCaptchaDone = '1';
                    form.submit();
                });
        });
    };

    form.addEventListener('submit', onSubmit);

    return () => {
        form.removeEventListener('submit', onSubmit);
    };
}

declare global {
    interface Window {
        grecaptcha?: {
            ready(callback: () => void): void;
            execute(siteKey: string, options: { action: string }): Promise<string>;
        };
    }
}
