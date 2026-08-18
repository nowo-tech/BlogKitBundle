/**
 * Symfony CollectionType add/remove for article resources.
 *
 * Boots `[data-controller="form-collection"]` without a host Stimulus application.
 */

const PROTOTYPE_DEFAULT = '__name__';

/**
 * Replace the CollectionType placeholder in prototype HTML.
 *
 * @param prototype Markup from `data-prototype`.
 * @param name Placeholder token (`__name__` by default).
 * @param index Next collection index.
 * @returns HTML with the placeholder replaced.
 */
export function replacePrototypeName(prototype: string, name: string, index: number): string {
    const token = name === '' ? PROTOTYPE_DEFAULT : name;

    return prototype.split(token).join(String(index));
}

/**
 * Parse trusted CollectionType prototype HTML into a node.
 *
 * @param html Prototype HTML after index replacement.
 * @returns The first element child, or null.
 */
export function parsePrototypeElement(html: string): HTMLElement | null {
    const parsed = new DOMParser().parseFromString(`<div>${html}</div>`, 'text/html');
    const wrap = parsed.body.firstElementChild;
    const first = wrap?.firstElementChild;

    return first instanceof HTMLElement ? first : null;
}

function nextIndex(root: HTMLElement, list: HTMLElement): number {
    const fromAttr = Number(root.dataset.formCollectionIndexValue || '0');
    const fromDom = list.querySelectorAll('[data-form-collection-target="entry"]').length;

    return Math.max(fromAttr, fromDom);
}

function syncEmpty(root: HTMLElement, list: HTMLElement): void {
    const empty = root.querySelector<HTMLElement>('[data-form-collection-target="empty"]');
    if (!(empty instanceof HTMLElement)) {
        return;
    }
    empty.hidden = list.querySelectorAll('[data-form-collection-target="entry"]').length > 0;
}

/**
 * Add one collection entry from the Symfony prototype.
 *
 * @param root Collection root (`data-controller="form-collection"`).
 * @returns The inserted element, or null when the prototype is missing.
 */
export function addCollectionEntry(root: HTMLElement): HTMLElement | null {
    const list = root.querySelector<HTMLElement>('[data-form-collection-target="list"]');
    if (!(list instanceof HTMLElement)) {
        return null;
    }

    const prototype = root.getAttribute('data-prototype') ?? '';
    if (prototype === '') {
        return null;
    }

    const name = root.dataset.formCollectionPrototypeNameValue || PROTOTYPE_DEFAULT;
    const index = nextIndex(root, list);
    const element = parsePrototypeElement(replacePrototypeName(prototype, name, index));
    if (!(element instanceof HTMLElement)) {
        return null;
    }

    list.appendChild(element);
    root.dataset.formCollectionIndexValue = String(index + 1);
    syncEmpty(root, list);

    return element;
}

/**
 * Remove a collection entry.
 *
 * @param entry Entry element (`data-form-collection-target="entry"`).
 * @returns void
 */
export function removeCollectionEntry(entry: HTMLElement): void {
    const root = entry.closest<HTMLElement>('[data-controller="form-collection"]');
    entry.remove();
    if (root instanceof HTMLElement) {
        const list = root.querySelector<HTMLElement>('[data-form-collection-target="list"]');
        if (list instanceof HTMLElement) {
            syncEmpty(root, list);
        }
    }
}

function onDocumentClick(event: Event): void {
    const target = event.target;
    if (!(target instanceof Element)) {
        return;
    }

    const addTrigger = target.closest('[data-action="form-collection#add"]');
    if (addTrigger instanceof HTMLElement) {
        const root = addTrigger.closest<HTMLElement>('[data-controller="form-collection"]');
        if (root instanceof HTMLElement) {
            event.preventDefault();
            addCollectionEntry(root);
        }
        return;
    }

    const removeTrigger = target.closest('[data-action="form-collection#remove"]');
    if (removeTrigger instanceof HTMLElement) {
        const entry = removeTrigger.closest<HTMLElement>('[data-form-collection-target="entry"]');
        if (entry instanceof HTMLElement) {
            event.preventDefault();
            removeCollectionEntry(entry);
        }
    }
}

let bound = false;

/**
 * Bind delegated click handlers for collection add/remove.
 *
 * @returns void
 */
export function bindFormCollection(): void {
    if (bound) {
        return;
    }
    bound = true;
    document.addEventListener('click', onDocumentClick);
}

/**
 * Reset the singleton click binding (tests only).
 *
 * @returns void
 */
export function resetFormCollectionBinding(): void {
    if (!bound) {
        return;
    }
    document.removeEventListener('click', onDocumentClick);
    bound = false;
}
