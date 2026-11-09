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
});
