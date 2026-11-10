import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const root = join(process.cwd(), 'app/Views');
const phpFiles = readdirSync(root, { recursive: true })
    .map(String)
    .filter((f) => f.endsWith('.php'));

describe('view guards', () => {
    it('uses no h-screen / min-h-screen outside comments', () => {
        const offenders = phpFiles.filter((f) =>
            readFileSync(join(root, f), 'utf8')
                .split('\n')
                .some((l) => !/^\s*(\*|\/\/)/.test(l) && /\b(min-)?h-screen\b/.test(l)),
        );
        expect(offenders).toEqual([]);
    });

    it('head.php viewport meta enables safe-area insets and keyboard resize', () => {
        const head = readFileSync(join(root, 'layouts/partials/head.php'), 'utf8');
        expect(head).toContain(
            'content="width=device-width, initial-scale=1.0, viewport-fit=cover, interactive-widget=resizes-content"',
        );
    });

    it('auth pages carry no fixed pixel widths and no sub-16px phone inputs', () => {
        const authViews = phpFiles.filter((f) => /^pages[\\/]auth[\\/]/.test(f));
        expect(authViews.length).toBeGreaterThan(0);
        const offenders = [];
        for (const f of authViews) {
            const src = readFileSync(join(root, f), 'utf8');
            if (/\bw-\[\d+px\]/.test(src)) offenders.push(`${f}: fixed px width`);
            for (const tag of src.match(/<(?:input|textarea)\b(?:<\?.*?\?>|[^>])*>/gs) ?? []) {
                if (/type="(hidden|checkbox|radio)"/.test(tag)) continue;
                // invitation and password-reset share a $field class string that holds text-base
                if (!/\btext-base\b/.test(tag) && !tag.includes('<?= $field ?>')) offenders.push(`${f}: ${tag.slice(0, 60)}`);
            }
        }
        expect(offenders).toEqual([]);
    });
});
