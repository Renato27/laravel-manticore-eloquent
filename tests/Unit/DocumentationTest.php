<?php

/**
 * The README is only the entry point, so a broken link means a page nobody can reach.
 * Checked offline: relative links and their anchors, not external URLs.
 */
function documentationPath(string $file = ''): string
{
    return dirname(__DIR__, 2).'/'.$file;
}

/**
 * @return array<int, string>
 */
function documentationFiles(): array
{
    return [
        'README.md',
        'CONTRIBUTING.md',
        ...array_map(static fn (string $path) => 'docs/'.basename($path), glob(documentationPath('docs/*.md'))),
    ];
}

function withoutCode(string $markdown): string
{
    return preg_replace(['/^```.*?^```/ms', '/`[^`\n]*`/'], '', $markdown);
}

/**
 * @return array<int, string>
 */
function relativeLinks(string $markdown): array
{
    $markdown = withoutCode($markdown);

    preg_match_all('/\]\(([^)\s]+)\)/', $markdown, $inline);
    preg_match_all('/^\[[^\]]+\]:\s*(\S+)/m', $markdown, $reference);

    return array_values(array_filter(
        [...$inline[1], ...$reference[1]],
        static fn (string $link) => ! preg_match('/^[a-z]+:/i', $link)
    ));
}

/**
 * The anchors GitHub generates for a page's headings.
 *
 * @return array<int, string>
 */
function headingAnchors(string $markdown): array
{
    preg_match_all('/^#{1,6}\s+(.+?)\s*$/m', preg_replace('/^```.*?^```/ms', '', $markdown), $headings);

    return array_map(
        static fn (string $heading) => str_replace(' ', '-', preg_replace('/[^\p{L}\p{N} _-]/u', '', mb_strtolower($heading))),
        $headings[1]
    );
}

it('links only to files and headings that exist', function (string $file) {
    expect(documentationPath($file))->toBeFile();

    $markdown = file_get_contents(documentationPath($file));

    foreach (relativeLinks($markdown) as $link) {
        [$target, $anchor] = array_pad(explode('#', $link, 2), 2, null);

        $path = $target === '' ? documentationPath($file) : dirname(documentationPath($file)).'/'.$target;

        expect(file_exists($path))->toBeTrue("{$file} links to a missing file: {$link}");

        if ($anchor !== null) {
            expect(headingAnchors(file_get_contents($path)))->toContain($anchor);
        }
    }
})->with(documentationFiles());

it('reaches every docs page from the README', function () {
    $links = relativeLinks(file_get_contents(documentationPath('README.md')));

    foreach (glob(documentationPath('docs/*.md')) as $page) {
        expect($links)->toContain('docs/'.basename($page));
    }
});

it('points the README at the contributing guide and the license', function () {
    expect(relativeLinks(file_get_contents(documentationPath('README.md'))))
        ->toContain('CONTRIBUTING.md')
        ->toContain('LICENSE');
});
