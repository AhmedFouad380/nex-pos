<?php

/** Every __('English text') used in app/ must have an Arabic translation in lang/ar.json. */
it('translates every UI string to Arabic', function () {
    $ar = json_decode(file_get_contents(lang_path('ar.json')), true);
    $missing = [];

    $files = [
        ...iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()))),
        ...iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')))),
    ];
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        preg_match_all("/__\\('([^']+)'/", file_get_contents($file->getPathname()), $m);
        foreach ($m[1] as $key) {
            if (! preg_match('/^[a-z_]+\\./', $key) && ! isset($ar[$key])) {
                $missing[] = $key;
            }
        }
    }

    expect(array_unique($missing))->toBeEmpty();
});

/** On case-insensitive filesystems __('Cash') would load lang/ar/cash.php and return an array. */
it('never uses a UI string that collides with a translation group file', function () {
    $groups = array_map(fn ($f) => strtolower(basename($f, '.php')), glob(lang_path('ar/*.php')));
    $offenders = [];

    foreach ([app_path(), resource_path('views')] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->getExtension() === 'php') {
                preg_match_all("/__\\('([^'.]+)'\\)/", file_get_contents($file->getPathname()), $m);
                $offenders = [...$offenders, ...array_filter($m[1], fn ($k) => in_array(strtolower($k), $groups, true))];
            }
        }
    }

    expect($offenders)->toBeEmpty();
});
