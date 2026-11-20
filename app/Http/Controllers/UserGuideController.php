<?php

namespace App\Http\Controllers;

use App\Settings\GeneralSettings;
use App\Support\Help\HelpTopics;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;

/**
 * دليل الاستخدام: every help file of resources/help/guide.php in one printable page.
 * php artisan guide:build prints it to public/guide/user-guide.pdf with Chrome.
 */
class UserGuideController extends Controller
{
    public function __invoke(): View
    {
        return view('guide.print', [
            'parts' => self::parts(),
            'business' => rescue(fn () => app(GeneralSettings::class)->business_name, '', false),
        ]);
    }

    /** @return list<array{title: string, intro: string, topics: list<array{key: string, title: string, html: HtmlString}>}> */
    public static function parts(): array
    {
        return collect(require resource_path('help/guide.php'))->map(fn (array $part) => [
            'title' => $part['title'],
            'intro' => $part['intro'],
            'topics' => collect($part['topics'])->map(fn (string $key) => HelpTopics::find($key))->filter()->values()->all(),
        ])->all();
    }
}
