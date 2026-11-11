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

    it('component calls that pass dialog-shaping keys also pass saveData', () => {
        // Index just past the bracket that closes the one at `open`, skipping PHP strings and comments.
        const closeOf = (src, open) => {
            let depth = 0;
            for (let i = open; i < src.length; i++) {
                const c = src[i];
                if (src.startsWith('//', i) || src.startsWith('/*', i)) {
                    const line = src[i + 1] === '/';
                    const end = src.indexOf(line ? '\n' : '*/', i + 2);
                    i = end < 0 ? src.length : end + (line ? 0 : 1);
                } else if (c === "'" || c === '"') {
                    for (i++; i < src.length && src[i] !== c; i++) if (src[i] === '\\') i++;
                } else if ('([{'.includes(c)) depth++;
                else if (')]}'.includes(c) && --depth === 0) return i + 1;
            }
            return src.length;
        };
        const offenders = [];
        for (const f of phpFiles) {
            const src = readFileSync(join(root, f), 'utf8');
            for (const m of src.matchAll(/\bview\(\s*'components\/[^']+'\s*,\s*\[/g)) {
                const arrayStart = m.index + m[0].length - 1;
                const arrayEnd = closeOf(src, arrayStart);
                const options = src.slice(arrayEnd, closeOf(src, m.index + m[0].indexOf('(')));
                if (/'(variant|centered|topAction|size)'\s*=>/.test(src.slice(arrayStart, arrayEnd)) && !options.includes('saveData')) {
                    offenders.push(`${f}:${src.slice(0, m.index).split('\n').length}`);
                }
            }
        }
        expect(offenders).toEqual([]);
    });
});
