<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ __('User guide') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=cairo:400,600,700,800&display=swap" rel="stylesheet">
    <style>
        @page { size: A4; margin: 16mm 14mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: Cairo, 'Geeza Pro', Tahoma, sans-serif; color: #1f2937; font-size: 11.5pt; line-height: 1.75; margin: 0; }
        a { color: inherit; text-decoration: none; }

        /* cover */
        .cover { height: 255mm; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; page-break-after: always; }
        .cover .badge { background: #f59e0b; color: #fff; padding: 4px 18px; border-radius: 99px; font-weight: 700; font-size: 11pt; }
        .cover h1 { font-size: 40pt; margin: 18px 0 6px; font-weight: 800; }
        .cover p { color: #6b7280; font-size: 14pt; margin: 0; }
        .cover .business { margin-top: 40px; font-size: 16pt; font-weight: 700; color: #374151; }
        .cover .date { margin-top: 6px; font-size: 10pt; color: #9ca3af; }

        /* table of contents */
        .toc { page-break-after: always; }
        .toc h2 { font-size: 22pt; margin: 0 0 14px; }
        .toc ol { padding: 0; margin: 0; list-style: none; counter-reset: part; }
        .toc > ol > li { counter-increment: part; margin-bottom: 10px; break-inside: avoid; }
        .toc > ol > li > a { font-weight: 700; font-size: 13pt; }
        .toc > ol > li > a::before { content: counter(part) ". "; color: #d97706; }
        .toc ul { margin: 2px 22px 0 0; padding: 0; list-style: none; columns: 2; column-gap: 24px; color: #4b5563; font-size: 10.5pt; }

        /* parts and topics */
        .part { page-break-before: always; }
        .part-head { border-bottom: 3px solid #f59e0b; padding-bottom: 8px; margin-bottom: 18px; }
        .part-head .num { color: #d97706; font-weight: 800; font-size: 12pt; }
        .part-head h2 { font-size: 26pt; margin: 0; font-weight: 800; }
        .part-head p { margin: 2px 0 0; color: #6b7280; }
        .topic { margin-bottom: 26px; }
        .topic + .topic { page-break-before: always; }
        .topic > h3 { font-size: 18pt; margin: 0 0 8px; font-weight: 800; color: #111827; }
        .body h2 { font-size: 13.5pt; margin: 18px 0 6px; color: #b45309; break-after: avoid; }
        .body h3 { font-size: 12pt; margin: 14px 0 4px; break-after: avoid; }
        .body p { margin: 6px 0; }
        .body ul, .body ol { margin: 6px 0; padding-right: 22px; }
        .body li { margin: 2px 0; }
        .body img { display: block; max-width: 100%; max-height: 200mm; margin: 10px auto 12px; border: 1px solid #e5e7eb; border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .06); break-inside: avoid; }
        .body table { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 10.5pt; break-inside: avoid; }
        .body th, .body td { border: 1px solid #e5e7eb; padding: 5px 8px; text-align: right; vertical-align: top; }
        .body th { background: #fef3c7; }
        .body blockquote { margin: 10px 0; padding: 8px 12px; background: #fffbeb; border-right: 4px solid #f59e0b; border-radius: 6px; break-inside: avoid; }
        .body blockquote p { margin: 0; }
        .body code { background: #f3f4f6; padding: 1px 5px; border-radius: 4px; font-size: 10pt; direction: ltr; unicode-bidi: embed; }
        .body strong { color: #111827; }
    </style>
</head>
<body>
    <section class="cover">
        <span class="badge">{{ __('User guide') }}</span>
        <h1>دليل استخدام النظام</h1>
        <p>نقاط البيع، والمخزون، والحسابات، والمرتبات</p>
        @if (filled($business) && $business !== 'My Business')
            <div class="business">{{ $business }}</div>
        @endif
        <div class="date">{{ now()->format('Y/m/d') }}</div>
    </section>

    <section class="toc">
        <h2>المحتويات</h2>
        <ol>
            @foreach ($parts as $p => $part)
                <li>
                    <a href="#part-{{ $p }}">{{ $part['title'] }}</a>
                    <ul>
                        @foreach ($part['topics'] as $topic)
                            <li><a href="#topic-{{ $topic['key'] }}">{{ $topic['title'] }}</a></li>
                        @endforeach
                    </ul>
                </li>
            @endforeach
        </ol>
    </section>

    @foreach ($parts as $p => $part)
        <section class="part" id="part-{{ $p }}">
            <header class="part-head">
                <div class="num">الجزء {{ $p + 1 }}</div>
                <h2>{{ $part['title'] }}</h2>
                <p>{{ $part['intro'] }}</p>
            </header>
            @foreach ($part['topics'] as $topic)
                <article class="topic" id="topic-{{ $topic['key'] }}">
                    <h3>{{ $topic['title'] }}</h3>
                    <div class="body">{{ $topic['html'] }}</div>
                </article>
            @endforeach
        </section>
    @endforeach
</body>
</html>
