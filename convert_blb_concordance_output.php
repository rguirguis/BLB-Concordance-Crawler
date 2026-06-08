<?php
/**
 * Convert BLB concordance output into canonical Obsidian verse notes,
 * then generate Strong-number hub notes with Dataview sections.
 *
 * Usage:
 *   php convert_blb_concordance_output.php --input sword.md --strong H2719
 *   php convert_blb_concordance_output.php --input H3858.md --verse-root "Bible/Verses" --strong-root "Bible/Strongs"
 *
 * Options:
 *   --input FILE[,FILE...]   One or more crawler output files
 *   --strong HNNNN          Strong number to assign when input file has a single Strong
 *   --verse-root DIR        Verse note root directory (default: Bible/Verses)
 *   --strong-root DIR       Strong hub note root directory (default: Bible/Strongs)
 *   --dry-run               Print actions without writing files
 *   --help, -h              Show help and exit
 */

declare(strict_types=1);

const BOOK_MAP = [
    'Gen' => 'Genesis',            'Exo' => 'Exodus',             'Lev' => 'Leviticus',
    'Num' => 'Numbers',            'Deu' => 'Deuteronomy',        'Jos' => 'Joshua',
    'Jdg' => 'Judges',             'Rth' => 'Ruth',               '1Sa' => '1 Samuel',
    '2Sa' => '2 Samuel',           '1Ki' => '1 Kings',            '2Ki' => '2 Kings',
    '1Ch' => '1 Chronicles',       '2Ch' => '2 Chronicles',       'Ezr' => 'Ezra',
    'Neh' => 'Nehemiah',           'Est' => 'Esther',             'Job' => 'Job',
    'Psa' => 'Psalms',             'Pro' => 'Proverbs',           'Ecc' => 'Ecclesiastes',
    'Sng' => 'Song of Solomon',    'Isa' => 'Isaiah',             'Jer' => 'Jeremiah',
    'Lam' => 'Lamentations',       'Eze' => 'Ezekiel',            'Dan' => 'Daniel',
    'Hos' => 'Hosea',              'Joe' => 'Joel',               'Amo' => 'Amos',
    'Oba' => 'Obadiah',            'Jon' => 'Jonah',              'Mic' => 'Micah',
    'Nah' => 'Nahum',              'Hab' => 'Habakkuk',           'Zep' => 'Zephaniah',
    'Hag' => 'Haggai',             'Zec' => 'Zechariah',          'Mal' => 'Malachi',
    'Mat' => 'Matthew',            'Mar' => 'Mark',               'Luk' => 'Luke',
    'Joh' => 'John',               'Act' => 'Acts',               'Rom' => 'Romans',
    '1Co' => '1 Corinthians',      '2Co' => '2 Corinthians',      'Gal' => 'Galatians',
    'Eph' => 'Ephesians',          'Phi' => 'Philippians',        'Col' => 'Colossians',
    '1Th' => '1 Thessalonians',    '2Th' => '2 Thessalonians',    '1Ti' => '1 Timothy',
    '2Ti' => '2 Timothy',          'Tit' => 'Titus',              'Phm' => 'Philemon',
    'Heb' => 'Hebrews',            'Jam' => 'James',              '1Pe' => '1 Peter',
    '2Pe' => '2 Peter',            '1Jo' => '1 John',             '2Jo' => '2 John',
    '3Jo' => '3 John',             'Jud' => 'Jude',               'Rev' => 'Revelation',
];

$inputFiles = [];
$strongArg = '';
$verseRoot = 'Bible/Verses';
$strongRoot = 'Bible/Strongs';
$dryRun = false;
$showHelp = false;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    switch ($arg) {
        case '--help':
        case '-h':
            $showHelp = true;
            break;
        case '--input':
            $i++;
            if (!isset($argv[$i]) || trim($argv[$i]) === '') {
                fwrite(STDERR, "Missing value for --input\n");
                exit(1);
            }
            $inputFiles = array_merge($inputFiles, array_map('trim', explode(',', $argv[$i])));
            break;
        case '--strong':
            $i++;
            $strongArg = strtoupper(trim($argv[$i] ?? ''));
            break;
        case '--verse-root':
            $i++;
            $verseRoot = trim($argv[$i] ?? $verseRoot);
            break;
        case '--strong-root':
            $i++;
            $strongRoot = trim($argv[$i] ?? $strongRoot);
            break;
        case '--dry-run':
            $dryRun = true;
            break;
        default:
            if (str_starts_with($arg, '-')) {
                fwrite(STDERR, "Unknown option: {$arg}\n");
                exit(1);
            }
            $inputFiles[] = $arg;
            break;
    }
}

if ($showHelp || count($inputFiles) === 0) {
    printHelp();
    exit($showHelp ? 0 : 1);
}

$inputFiles = array_values(array_filter(array_unique($inputFiles), '\strlen'));
if (count($inputFiles) === 0) {
    fwrite(STDERR, "No input files provided.\n");
    exit(1);
}

$entries = [];
foreach ($inputFiles as $inputPath) {
    if (!is_file($inputPath)) {
        fwrite(STDERR, "Input file not found: {$inputPath}\n");
        continue;
    }
    $sourceStrong = $strongArg;
    if ($sourceStrong === '') {
        $sourceStrong = inferStrongFromFilename($inputPath);
    }
    if ($sourceStrong === null) {
        fwrite(STDERR, "Unable to infer Strong number for input file: {$inputPath}\n");
        continue;
    }
    $inputText = file_get_contents($inputPath);
    if ($inputText === false) {
        fwrite(STDERR, "Unable to read input file: {$inputPath}\n");
        continue;
    }
    $parsed = parseCrawlerOutput($inputText, $sourceStrong);
    if (empty($parsed)) {
        fwrite(STDERR, "No valid verse entries found in: {$inputPath}\n");
        continue;
    }
    $entries = array_merge($entries, $parsed);
}

if (empty($entries)) {
    fwrite(STDERR, "No verse entries were parsed from the input files.\n");
    exit(1);
}

if (!$dryRun) {
    ensureDirectory($verseRoot);
    ensureDirectory($strongRoot);
}

$processedStrongNumbers = [];
foreach ($entries as $entry) {
    $notePath = getVerseNotePath($entry['book'], $entry['chapter'], $entry['verse'], $verseRoot);
    $createdOrUpdated = processVerseNote($notePath, $entry, $dryRun);
    echo ($createdOrUpdated ? "Updated: {$notePath}\n" : "Skipped: {$notePath}\n");
    $processedStrongNumbers[] = $entry['strong'];
}

$strongNumbers = array_unique(array_map('strtoupper', $processedStrongNumbers));
sort($strongNumbers, SORT_NATURAL);

// Collect all Strong numbers from the verse root so hubs reflect the full note set.
$allStrongNumbers = collectStrongNumbersFromVerseNotes($verseRoot);
$allStrongNumbers = array_unique(array_merge($allStrongNumbers, $strongNumbers));
sort($allStrongNumbers, SORT_NATURAL);

foreach ($allStrongNumbers as $strong) {
    $hubPath = $strongRoot . DIRECTORY_SEPARATOR . strtoupper($strong) . '.md';
    $updated = updateStrongHubNote($hubPath, $strong, $verseRoot, $dryRun);
    echo ($updated ? "Updated hub: {$hubPath}\n" : "Created hub: {$hubPath}\n");
}

exit(0);

function printHelp(): void
{
    $help = <<<'HELP'
Usage:
  php convert_blb_concordance_output.php --input FILE [options]

Options:
  --input FILE[,FILE...]   One or more crawler output files
  --strong HNNNN          Strong number if files do not embed it in their name
  --verse-root DIR        Verse note root directory (default: Bible/Verses)
  --strong-root DIR       Strong hub note root directory (default: Bible/Strongs)
  --dry-run               Print actions without writing files
  --help, -h              Show help and exit

Examples:
  php convert_blb_concordance_output.php --input h2719_sword.md --strong H2719
  php convert_blb_concordance_output.php --input "H2719.md,H3858.md" --verse-root "Bible/Verses" --strong-root "Bible/Strongs"
HELP;
    fwrite(STDOUT, $help . PHP_EOL);
}

function inferStrongFromFilename(string $path): ?string
{
    if (preg_match('/\b([HG]\d{1,4})\b/i', basename($path), $match)) {
        return strtoupper($match[1]);
    }
    return null;
}

function parseCrawlerOutput(string $text, string $strong): array
{
    $strong = strtoupper($strong);
    $lines = preg_split('/\r\n|\r|\n/', trim($text));
    if ($lines === false) {
        return [];
    }

    $blocks = [];
    $current = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            if (!empty($current)) {
                $blocks[] = $current;
                $current = [];
            }
            continue;
        }
        $current[] = $trimmed;
    }
    if (!empty($current)) {
        $blocks[] = $current;
    }

    $parsed = [];
    foreach ($blocks as $block) {
        if (count($block) === 0) {
            continue;
        }
        $first = $block[0];
        $ref = parseVerseReference($first);
        if ($ref === null) {
            continue;
        }

        $english = '';
        $arabic = '';
        if (count($block) >= 3) {
            $english = $block[1];
            $arabic = $block[2];
        } elseif (count($block) === 2) {
            if (containsArabicScript($block[1]) || str_contains($block[1], '==')) {
                $arabic = $block[1];
            } else {
                $english = $block[1];
            }
        }

        $bookName = toFullBookName($ref['abbr']);
        $parsed[] = [
            'abbr' => $ref['abbr'],
            'book' => $bookName,
            'chapter' => $ref['chapter'],
            'verse' => $ref['verse'],
            'ref' => $ref['label'],
            'english' => $english,
            'arabic' => $arabic,
            'strong' => $strong,
        ];
    }

    return $parsed;
}

function parseVerseReference(string $text): ?array
{
    if (preg_match('/^([1-3]?[A-Za-z]+)\s+(\d+):(\d+)$/', trim($text), $match)) {
        return [
            'abbr' => $match[1],
            'chapter' => $match[2],
            'verse' => $match[3],
            'label' => $match[1] . ' ' . $match[2] . '.' . $match[3],
        ];
    }
    return null;
}

function containsArabicScript(string $text): bool
{
    return preg_match('/\p{Arabic}/u', $text) === 1;
}

function toFullBookName(string $abbr): string
{
    $clean = ucfirst(strtolower($abbr));
    if (isset(BOOK_MAP[$clean])) {
        return BOOK_MAP[$clean];
    }
    foreach (BOOK_MAP as $key => $value) {
        if (strcasecmp($key, $abbr) === 0) {
            return $value;
        }
    }
    return $clean;
}

function getVerseNotePath(string $book, string $chapter, string $verse, string $root): string
{
    $safeBook = str_replace(['<', '>', ':', '"', '/', '\\', '|', '?', '*'], '', $book);
    return rtrim($root, "\/ ") . DIRECTORY_SEPARATOR . $safeBook . ' ' . $chapter . '.' . $verse . '.md';
}

function ensureDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }
    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        fwrite(STDERR, "Unable to create directory: {$path}\n");
        exit(1);
    }
}

function processVerseNote(string $notePath, array $entry, bool $dryRun): bool
{
    $text = is_file($notePath) ? file_get_contents($notePath) : '';
    if ($text === false) {
        throw new RuntimeException("Failed to read note: {$notePath}");
    }

    [$meta, $body] = parseNoteText($text);
    $meta = ensureVerseMetadata($meta, $entry);
    $body = updateVerseBody($body, $entry);

    $newText = buildNoteText($meta, $body);
    if ($dryRun) {
        return true;
    }

    if (is_file($notePath) && trim(file_get_contents($notePath)) === trim($newText)) {
        return false;
    }

    if (file_put_contents($notePath, $newText) === false) {
        throw new RuntimeException("Failed to write note: {$notePath}");
    }
    return true;
}

function parseNoteText(string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    if (str_starts_with($text, "---\n")) {
        $end = strpos($text, "\n---", 4);
        if ($end !== false) {
            $yaml = substr($text, 4, $end - 4);
            $rest = substr($text, $end + 5);
            return [parseYamlFrontMatter($yaml), trim($rest)];
        }
    }
    return [[], trim($text)];
}

function ensureVerseMetadata(array $meta, array $entry): array
{
    $meta['title'] = $meta['title'] ?? $entry['abbr'] . ' ' . $entry['chapter'] . '.' . $entry['verse'];
    $meta['book'] = $meta['book'] ?? $entry['book'];
    $meta['chapter'] = $meta['chapter'] ?? $entry['chapter'];
    $meta['verse'] = $meta['verse'] ?? $entry['verse'];

    $strongs = [];
    if (isset($meta['strongs']) && is_array($meta['strongs'])) {
        $strongs = $meta['strongs'];
    }
    $strongs[] = strtoupper($entry['strong']);
    $meta['strongs'] = array_values(array_unique(array_map('strtoupper', array_filter($strongs, 'strlen'))));

    if (!isset($meta['tags']) || !is_array($meta['tags'])) {
        $meta['tags'] = ['verse', 'bible', 'strong'];
    }

    return $meta;
}

function updateVerseBody(string $body, array $entry): string
{
    $body = trim($body);
    $hasArabicSection = preg_match('/^##\s+Arabic\b/im', $body) === 1;

    if ($body === '') {
        $body = $entry['english'];
        if ($entry['arabic'] !== '') {
            $body .= "\n\n## Arabic\n" . $entry['arabic'];
        }
        return trim($body);
    }

    if ($entry['arabic'] === '') {
        return $body;
    }

    if ($hasArabicSection) {
        return replaceArabicSection($body, $entry['arabic']);
    }

    return trim($body) . "\n\n## Arabic\n" . $entry['arabic'];
}

function replaceArabicSection(string $body, string $arabic): string
{
    return preg_replace(
        '/##\s+Arabic\b[\s\S]*?(?=(?:\n##\s+|\z))/i',
        "## Arabic\n" . $arabic . "\n",
        $body,
        1
    );
}

function buildNoteText(array $meta, string $body): string
{
    $yaml = buildYamlFrontMatter($meta);
    if ($body !== '') {
        return $yaml . "\n\n" . trim($body) . "\n";
    }
    return $yaml . "\n";
}

function parseYamlFrontMatter(string $yaml): array
{
    $lines = preg_split('/\n/', $yaml);
    if ($lines === false) {
        return [];
    }

    $meta = [];
    $stack = [&$meta];
    $indentStack = [0];
    $currentKey = null;

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $indent = strlen($line) - strlen(ltrim($line, ' '));
        while ($indent < end($indentStack)) {
            array_pop($indentStack);
            array_pop($stack);
        }

        if (preg_match('/^\s*-\s*(.+)$/', $line, $match)) {
            $value = parseYamlValue($match[1]);
            $parent = &$stack[count($stack) - 1];
            if (!is_array($parent)) {
                $parent = [];
            }
            $parent[] = $value;
            continue;
        }

        if (preg_match('/^\s*([^:]+):\s*(.*)$/', $line, $match)) {
            $key = trim($match[1]);
            $value = trim($match[2]);
            $parent = &$stack[count($stack) - 1];
            if ($value === '') {
                $parent[$key] = [];
                $stack[] = &$parent[$key];
                $indentStack[] = $indent + 2;
            } else {
                $parent[$key] = parseYamlValue($value);
            }
        }
    }

    return $meta;
}

function parseYamlValue(string $value)
{
    $value = trim($value);
    if ($value === 'true') {
        return true;
    }
    if ($value === 'false') {
        return false;
    }
    if (is_numeric($value)) {
        if (ctype_digit($value)) {
            return (int)$value;
        }
        return (float)$value;
    }
    return $value;
}

function buildYamlFrontMatter(array $meta): string
{
    $lines = ['---'];
    $priority = ['title', 'book', 'chapter', 'verse', 'strongs', 'tags', 'categories'];

    foreach ($priority as $key) {
        if (array_key_exists($key, $meta)) {
            $lines = array_merge($lines, buildYamlKey($key, $meta[$key]));
            unset($meta[$key]);
        }
    }

    ksort($meta, SORT_STRING);
    foreach ($meta as $key => $value) {
        $lines = array_merge($lines, buildYamlKey($key, $value));
    }

    $lines[] = '---';
    return implode("\n", $lines);
}

function buildYamlKey(string $key, $value): array
{
    if (is_array($value)) {
        if (array_is_list($value)) {
            $lines = ["{$key}:"];
            foreach ($value as $item) {
                $lines[] = '  - ' . formatYamlScalar($item);
            }
            return $lines;
        }
        $lines = ["{$key}:"];
        foreach ($value as $subKey => $subValue) {
            $lines[] = '  ' . trim((string)$subKey) . ': ' . formatYamlScalar($subValue);
        }
        return $lines;
    }
    return ["{$key}: " . formatYamlScalar($value)];
}

function formatYamlScalar($value): string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }
    $scalar = (string)$value;
    if ($scalar === '' || preg_match('/^[-?:,[\]{}#&*!|>\'"%@`]|\s|\s$|[:]\s|\n/', $scalar)) {
        return '"' . addslashes($scalar) . '"';
    }
    return $scalar;
}

function collectStrongNumbersFromVerseNotes(string $verseRoot): array
{
    $strongs = [];
    if (!is_dir($verseRoot)) {
        return [];
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($verseRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
            $text = file_get_contents((string)$file);
            if ($text === false) {
                continue;
            }
            [$meta,] = parseNoteText($text);
            if (isset($meta['strongs']) && is_array($meta['strongs'])) {
                foreach ($meta['strongs'] as $strong) {
                    if (is_string($strong) && $strong !== '') {
                        $strongs[] = strtoupper($strong);
                    }
                }
            }
        }
    }
    return array_unique($strongs);
}

function updateStrongHubNote(string $hubPath, string $strong, string $verseRoot, bool $dryRun): bool
{
    $strong = strtoupper($strong);
    $text = is_file($hubPath) ? file_get_contents($hubPath) : '';
    if ($text === false) {
        throw new RuntimeException("Failed to read hub note: {$hubPath}");
    }

    [$meta, $body] = parseNoteText($text);
    $meta['title'] = $meta['title'] ?? $strong;
    $meta['strong'] = $meta['strong'] ?? $strong;

    $body = trim($body);
    if ($body === '') {
        $body = "## Overview\n\n";
    }

    $body = ensureHubSection($body, 'verses', $strong, buildVersesDataview($strong));
    $body = ensureHubSection($body, 'categories', $strong, buildCategoriesDataview($strong));

    $newText = buildNoteText($meta, $body);
    if ($dryRun) {
        return true;
    }
    if (is_file($hubPath) && trim(file_get_contents($hubPath)) === trim($newText)) {
        return false;
    }
    if (file_put_contents($hubPath, $newText) === false) {
        throw new RuntimeException("Failed to write hub note: {$hubPath}");
    }
    return true;
}

function ensureHubSection(string $body, string $section, string $strong, string $sectionContent): string
{
    $start = "<!-- {$section}-{$strong}-start -->";
    $end = "<!-- {$section}-{$strong}-end -->";
    $pattern = '/' . preg_quote($start, '/') . '[\s\S]*?' . preg_quote($end, '/') . '/';

    if (preg_match($pattern, $body)) {
        return preg_replace($pattern, $sectionContent, $body, 1);
    }

    if ($body !== '') {
        return trim($body) . "\n\n" . $sectionContent;
    }

    return $sectionContent;
}

function buildVersesDataview(string $strong): string
{
    return <<<MD
<!-- verses-{$strong}-start -->
## Verses

```dataview
table book, chapter, verse
from "Bible/Verses"
where contains(strongs, "{$strong}")
sort book, chapter, verse
```
<!-- verses-{$strong}-end -->
MD;
}

function buildCategoriesDataview(string $strong): string
{
    return <<<MD
<!-- categories-{$strong}-start -->
## Verses by Category

```dataview
table file.link as Verse, categories.{$strong} as Category
from "Bible/Verses"
where contains(strongs, "{$strong}") and categories.{$strong}
group by categories.{$strong}
sort categories.{$strong}
```
<!-- categories-{$strong}-end -->
MD;
}
