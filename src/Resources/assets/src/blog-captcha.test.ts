import { afterEach, describe, expect, it, vi } from 'vitest';
import { bindCommentCaptcha } from './blog-captcha';

describe('blog-captcha', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        delete window.grecaptcha;
    });

    it('is a no-op without a recaptcha v3 form or site key', () => {
        document.body.innerHTML = '<form></form>';
        expect(bindCommentCaptcha()()).toBeUndefined();

        document.body.innerHTML = '<form data-blog-captcha="recaptcha_v3"></form>';
        expect(bindCommentCaptcha()()).toBeUndefined();
    });

    it('submits immediately when grecaptcha is missing', () => {
        document.body.innerHTML = '<form data-blog-captcha="recaptcha_v3" data-blog-captcha-sitekey="abc"></form>';
        const form = document.querySelector('form')!;
        const submit = vi.spyOn(form, 'submit').mockImplementation(() => undefined);
        const stop = bindCommentCaptcha();

        form.dispatchEvent(new Event('submit', { cancelable: true }));
        expect(form.dataset.blogCaptchaDone).toBe('1');
        expect(submit).toHaveBeenCalled();

        form.dispatchEvent(new Event('submit', { cancelable: true }));
        stop();
    });

    it('executes grecaptcha and reuses an existing token input', async () => {
        const execute = vi.fn().mockResolvedValue('tok-1');
        window.grecaptcha = {
            ready: (cb: () => void) => cb(),
            execute,
        };
        document.body.innerHTML = `
          <form data-blog-captcha="recaptcha_v3" data-blog-captcha-sitekey="abc" data-blog-captcha-action="blog_comment">
            <input type="hidden" name="g-recaptcha-response" value="">
          </form>`;
        const form = document.querySelector('form')!;
        const submit = vi.spyOn(form, 'submit').mockImplementation(() => undefined);
        bindCommentCaptcha();
        form.dispatchEvent(new Event('submit', { cancelable: true }));
        await Promise.resolve();
        expect(execute).toHaveBeenCalledWith('abc', { action: 'blog_comment' });
        expect(form.querySelector<HTMLInputElement>('input[name="g-recaptcha-response"]')?.value).toBe('tok-1');
        expect(submit).toHaveBeenCalled();
    });

    it('creates a hidden token field when missing', async () => {
        const execute = vi.fn().mockResolvedValue('tok-2');
        window.grecaptcha = {
            ready: (cb: () => void) => cb(),
            execute,
        };
        document.body.innerHTML = '<form data-blog-captcha="recaptcha_v3" data-blog-captcha-sitekey="site"></form>';
        const form = document.querySelector('form')!;
        vi.spyOn(form, 'submit').mockImplementation(() => undefined);
        bindCommentCaptcha();
        form.dispatchEvent(new Event('submit', { cancelable: true }));
        await Promise.resolve();
        expect(form.querySelector<HTMLInputElement>('input[name="g-recaptcha-response"]')?.value).toBe('tok-2');
    });
});
