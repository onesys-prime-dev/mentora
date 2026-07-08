<?php

if (! function_exists('vite')) {

    function vite(string $entry): string
    {
        /*
        |--------------------------------------------------------------------------
        | Development
        |--------------------------------------------------------------------------
        */

        if (ENVIRONMENT === 'development') {

            $viteServer = env('vite.server', 'http://localhost:5173');

            return implode(PHP_EOL, [

                '<script type="module" src="' . $viteServer . '/@vite/client"></script>',

                '<script type="module" src="' . $viteServer . '/' . $entry . '"></script>',

            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Production
        |--------------------------------------------------------------------------
        */

        $manifestPath = ROOTPATH . 'public/build/.vite/manifest.json';

        if (! file_exists($manifestPath)) {

            throw new RuntimeException(
                'Vite manifest not found. Run "npm run build".'
            );
        }

        $manifest = json_decode(
            file_get_contents($manifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (! isset($manifest[$entry])) {

            throw new RuntimeException(
                "Vite entry '{$entry}' not found."
            );
        }

        $asset = $manifest[$entry];

        $html = [];

        if (! empty($asset['css'])) {

            foreach ($asset['css'] as $css) {

                $html[] =
                    '<link rel="stylesheet" href="' .
                    base_url('build/' . $css) .
                    '">';
            }
        }

        $html[] =
            '<script type="module" src="' .
            base_url('build/' . $asset['file']) .
            '"></script>';

        return implode(PHP_EOL, $html);
    }
}