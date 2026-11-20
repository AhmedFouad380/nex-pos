<?php

namespace App\Support\Help;

use Filament\Pages\Page;
use Filament\Resources\Pages\Page as ResourcePage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The "?" help of every admin page, written as Markdown in resources/help/{locale}/.
 *
 * A page's key follows its URL: "sales.index", "sales.view", "settings.users.index", "reports.sales".
 * The file looked up is {key}.md, then the file of its parent: sales.view.md → sales.md.
 * Pages of the cash register panel are prefixed "pos.". The first "# " line is the title. A page without a file has no help button.
 */
final class HelpTopics
{
    /** @param  class-string  $page */
    public static function keyFor(string $page): ?string
    {
        if (is_subclass_of($page, ResourcePage::class)) {
            $resource = $page::getResource();
            foreach ($resource::getPages() as $name => $registration) {
                if ($registration->getPage() === $page) {
                    return str_replace('/', '.', $resource::getSlug()).'.'.$name;
                }
            }

            return null;
        }
        if (is_subclass_of($page, Page::class)) {
            // the cash register screen lives in its own panel: "pos.sell" (falls back to pos.md)
            $prefix = str_starts_with($page, 'App\\Filament\\Pos\\') ? 'pos.' : '';

            return $prefix.str_replace('/', '.', $page::getSlug());
        }

        return null;
    }

    /** @return array{key: string, title: string, html: HtmlString}|null */
    public static function forPage(string $page): ?array
    {
        $key = self::keyFor($page);

        return $key === null ? null : self::find($key);
    }

    /** @return array{key: string, title: string, html: HtmlString}|null */
    public static function find(string $key): ?array
    {
        $candidates = [$key];
        if (str_contains($key, '.')) {
            $candidates[] = Str::beforeLast($key, '.');
        }
        foreach ($candidates as $candidate) {
            $path = self::path($candidate);
            if ($path !== null) {
                return ['key' => $candidate, ...self::parse(File::get($path))];
            }
        }

        return null;
    }

    /** @return array{title: string, html: HtmlString} */
    public static function parse(string $markdown): array
    {
        $title = '';
        if (preg_match('/^#\s+(.+)$/m', $markdown, $m)) {
            $title = trim($m[1]);
            $markdown = (string) preg_replace('/^#\s+.+$\n?/m', '', $markdown, 1);
        }

        return ['title' => $title, 'html' => new HtmlString(Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]))];
    }

    /** @return list<string> every help key that has a file */
    public static function all(): array
    {
        $dir = resource_path('help/'.self::locale());

        return collect(File::isDirectory($dir) ? File::files($dir) : [])
            ->filter(fn ($file) => $file->getExtension() === 'md')
            ->map(fn ($file) => $file->getFilenameWithoutExtension())->sort()->values()->all();
    }

    private static function path(string $key): ?string
    {
        if (! preg_match('/^[a-z0-9.\-]+$/', $key)) {
            return null;
        }
        foreach (array_unique([self::locale(), 'ar']) as $locale) {
            $path = resource_path("help/{$locale}/{$key}.md");
            if (File::exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private static function locale(): string
    {
        return app()->getLocale() === 'en' ? 'en' : 'ar';
    }
}
