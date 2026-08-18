import { afterEach, describe, expect, it } from 'vitest';
import {
    addCollectionEntry,
    bindFormCollection,
    parsePrototypeElement,
    removeCollectionEntry,
    replacePrototypeName,
    resetFormCollectionBinding,
} from './blog-form-collection';

function mountCollection(withEmpty = true): HTMLElement {
    document.body.innerHTML = `
      <div data-controller="form-collection"
           data-prototype="<div data-form-collection-target=&quot;entry&quot;><input name=&quot;resources[__name__][title]&quot;></div>"
           data-form-collection-prototype-name-value="__name__"
           data-form-collection-index-value="0">
        <div data-form-collection-target="list"></div>
        ${withEmpty ? '<p data-form-collection-target="empty">none</p>' : ''}
        <button type="button" data-action="form-collection#add">add</button>
      </div>
    `;

    return document.querySelector<HTMLElement>('[data-controller="form-collection"]')!;
}

describe('blog-form-collection', () => {
    afterEach(() => {
        resetFormCollectionBinding();
        document.body.innerHTML = '';
    });

    it('replaces the prototype placeholder', () => {
        expect(replacePrototypeName('n=__name__', '__name__', 4)).toBe('n=4');
        expect(replacePrototypeName('n=__name__', '', 1)).toBe('n=1');
    });

    it('parses prototype HTML and rejects empty markup', () => {
        expect(parsePrototypeElement('<div class="ok"></div>')?.className).toBe('ok');
        expect(parsePrototypeElement('')).toBeNull();
    });

    it('adds and removes collection entries', () => {
        const root = mountCollection();
        const added = addCollectionEntry(root);
        expect(added).not.toBeNull();
        expect(root.querySelectorAll('[data-form-collection-target="entry"]')).toHaveLength(1);
        expect(root.querySelector<HTMLElement>('[data-form-collection-target="empty"]')?.hidden).toBe(
            true,
        );

        removeCollectionEntry(added!);
        expect(root.querySelectorAll('[data-form-collection-target="entry"]')).toHaveLength(0);
        expect(root.querySelector<HTMLElement>('[data-form-collection-target="empty"]')?.hidden).toBe(
            false,
        );
    });

    it('returns null without a list or prototype', () => {
        const empty = document.createElement('div');
        expect(addCollectionEntry(empty)).toBeNull();
        empty.innerHTML = '<div data-form-collection-target="list"></div>';
        expect(addCollectionEntry(empty)).toBeNull();
    });

    it('delegates add and remove clicks', () => {
        const root = mountCollection();
        bindFormCollection();
        bindFormCollection();
        root.querySelector('button')?.click();
        expect(root.querySelectorAll('[data-form-collection-target="entry"]')).toHaveLength(1);

        const remove = document.createElement('button');
        remove.setAttribute('data-action', 'form-collection#remove');
        root.querySelector('[data-form-collection-target="entry"]')?.appendChild(remove);
        remove.click();
        expect(root.querySelectorAll('[data-form-collection-target="entry"]')).toHaveLength(0);
    });

    it('returns null when prototype HTML has no element', () => {
        const root = mountCollection();
        root.setAttribute('data-prototype', 'not-an-element');
        expect(addCollectionEntry(root)).toBeNull();
    });

    it('adds entries when the empty hint is missing', () => {
        const root = mountCollection(false);
        expect(addCollectionEntry(root)).not.toBeNull();
    });

    it('removes an entry that is not inside a collection root', () => {
        const orphan = document.createElement('div');
        orphan.setAttribute('data-form-collection-target', 'entry');
        document.body.appendChild(orphan);
        removeCollectionEntry(orphan);
        expect(document.body.contains(orphan)).toBe(false);
    });

    it('removes an entry when the list target is missing', () => {
        document.body.innerHTML = `
          <div data-controller="form-collection">
            <div data-form-collection-target="entry"></div>
          </div>
        `;
        const entry = document.querySelector<HTMLElement>('[data-form-collection-target="entry"]')!;
        removeCollectionEntry(entry);
        expect(document.querySelector('[data-form-collection-target="entry"]')).toBeNull();
    });

    it('ignores click events whose target is not an Element', () => {
        bindFormCollection();
        document.dispatchEvent(new Event('click'));
        expect(document.querySelectorAll('[data-form-collection-target="entry"]')).toHaveLength(0);
    });

    it('ignores add clicks outside a collection root', () => {
        bindFormCollection();
        const stray = document.createElement('button');
        stray.setAttribute('data-action', 'form-collection#add');
        document.body.appendChild(stray);
        stray.click();
        expect(document.querySelectorAll('[data-form-collection-target="entry"]')).toHaveLength(0);
    });

    it('ignores clicks that are not collection actions', () => {
        mountCollection();
        bindFormCollection();
        const stray = document.createElement('button');
        document.body.appendChild(stray);
        stray.click();
        expect(document.querySelectorAll('[data-form-collection-target="entry"]')).toHaveLength(0);
    });
});
