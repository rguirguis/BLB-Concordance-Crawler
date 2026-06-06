<?php
/**
 * BLB Concordance Crawler  v4
 *
 * Usage:
 *   php blb_concordance_crawler.php --strong H2719 --output sword.md
 *   php blb_concordance_crawler.php --strong H2719 --dry-run --limit 5
 *
 * Options:
 *   --strong  HNNNN   Strong's number          (default: H2719)
 *   --output  FILE    Markdown output file      (default: concordance.md)
 *   --version STR     Arabic Bible version      (default: vdv)
 *   --limit   N       Process only first N entries (0 = all)
 *   --dry-run         Print parsed KJV entries to console; no Arabic fetch, no file
 *   --help, -h         Show this help message and exit
 *
 * Requirements: PHP 8.x with curl + mbstring (standard in PHP 8.4)
 */
declare(strict_types=1);

// ── Argument parsing ──────────────────────────────────────────────────────────
// Using a simple loop instead of match() to avoid PHP side-effect gotchas.

$strongsNum   = 'H2719';
$outputFile   = 'concordance.md';
$arabicVer    = 'vdv';
$limit        = 0;
$dryRun       = false;
$showHelp     = false;
$delaySeconds = 1.2;

function printHelp(): void {
    $help = <<<'HELP'
Usage:
  php blb_concordance_crawler.php [options]

Options:
  --help, -h           Show this help message and exit.
  --strong HNNNN       Strong's number to crawl (default: H2719)
  --output FILE        Markdown output file path (default: concordance.md)
  --version STR        Arabic Bible version: vdv or nav (default: vdv)
  --limit N            Process only the first N entries (0 = all)
  --dry-run            Print parsed KJV entries to the console; no Arabic fetch or file output

Examples:
  php blb_concordance_crawler.php --help
  php blb_concordance_crawler.php --strong H2719 --output sword.md
  php blb_concordance_crawler.php --strong G26 --output agape_nt.md --version nav --dry-run
  php blb_concordance_crawler.php --strong H3068 --limit 50 --output yhwh_sample.md

Notes:
  Requires PHP 8.4+ with curl and mbstring extensions.
  The script crawls Blue Letter Bible concordance pages and highlights the matching Arabic word for the requested Strong's number.
HELP;
    fwrite(STDOUT, $help . PHP_EOL);
}

for ($i = 1; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '--help':
        case '-h':       $showHelp = true;                                         break;
        case '--strong':  $strongsNum = strtoupper(trim($argv[++$i] ?? 'H2719')); break;
        case '--output':  $outputFile = trim($argv[++$i] ?? 'concordance.md');    break;
        case '--version': $arabicVer  = strtolower(trim($argv[++$i] ?? 'vdv'));   break;
        case '--limit':   $limit      = (int)($argv[++$i] ?? 0);                  break;
        case '--dry-run': $dryRun     = true;                                      break;
        default:
            $arg = $argv[$i];
            if (str_starts_with($arg, '-')) {
                fwrite(STDERR, "Unknown option: {$arg}\n");
                fwrite(STDERR, "Run php {$argv[0]} --help for usage.\n");
                exit(1);
            }
            break;
    }
}

if ($showHelp) {
    printHelp();
    exit(0);
}

// ── Book map ──────────────────────────────────────────────────────────────────
// BLB 3-letter abbr (title-case) → service.arabicbible.com book name
// Multi-part books use a space: '1 Samuel', '1 Kings', etc.

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

function toApiBook(string $abbr): string {
    $key = ucfirst(strtolower($abbr));
    if (isset(BOOK_MAP[$key])) return BOOK_MAP[$key];
    foreach (BOOK_MAP as $k => $v) if (strcasecmp($k, $abbr) === 0) return $v;
    return ucfirst(strtolower($abbr));
}

// ── HTTP ──────────────────────────────────────────────────────────────────────

function httpGet(string $url, bool $json = false): string|false {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            $json ? 'Accept: application/json, text/json, */*'
                  : 'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9,ar;q=0.8',
        ],
        CURLOPT_ENCODING       => '',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);
    if ($err)        { fwrite(STDERR, "  [curl] $url\n         $err\n"); return false; }
    if ($status !== 200) { fwrite(STDERR, "  [HTTP $status] $url\n"); return false; }
    return (string)$body;
}

// ── BLB parsing ───────────────────────────────────────────────────────────────
//
// Confirmed HTML structure (from live page):
//
//   [checkbox img][anchor: href=/kjv/gen/3/24/s_3024  text=""]   ← no label, skip
//   [checkbox img][anchor: href=/kjv/gen/3/24/s_3024  text="Gen 3:24"]
//   - So he drove out[H1644](link) the man;[H120](link) ...
//     sword[H2719](LINK) which turned ...
//   Tools
//   [checkbox img][anchor: href=/kjv/gen/27/40/...  text=""]
//   ...
//
// The word "Tools" in plain text terminates each entry.
// The SAME word appears in navigation menus ABOVE the concordance, so we must
// slice from "Concordance Results Shown" before splitting on Tools.
//
// The ➔ arrows are BLB's HTML entity &#10148; used for repeated Strong's words
// (e.g. a verb that maps to two Strong's numbers). They carry no meaning for us.
//
// The verse text structure per word is one of two forms:
//   A) word<a href="/lexicon/H2719/kjv/">H2719</a>        (word before anchor)
//   B) <a href="/lexicon/H2719/kjv/">H2719</a>word        (rare, word after)
// The anchor text is the Strong's number, NOT the word. The word is adjacent text.

function parseBLBPage(string $html, string $strongsNum): array {
    $target = strtoupper($strongsNum);
    $lexUrl = "https://www.blueletterbible.org/lexicon/{$target}/kjv/";

    // ── 1. Isolate concordance section ────────────────────────────────────────
    // Find the marker that starts the actual concordance entries.
    // "Concordance Results Shown Using the KJV" is the heading; the first
    // sub-heading is "Concordance Results" followed immediately by the entries.
    $marker = 'Concordance Results Shown Using the KJV';
    $start  = strpos($html, $marker);
    if ($start === false) return [];
    $section = substr($html, $start);
    foreach (['Search Results Continued', 'Search Results in Other Versions'] as $endMarker) {
        $endPos = strpos($section, $endMarker);
        if ($endPos !== false) {
            $section = substr($section, 0, $endPos);
            break;
        }
    }

    // ── 2. Extract the total count from "Total: NNNx" for diagnostics ─────────
    // (not used for logic, just informational)

    // ── 3. Find all verse-line anchors in the concordance section ─────────────
    // Each verse line has the form:
    //   <a href="/kjv/{book}/{ch}/{vs}/s_{n}">{label}</a> - verse text...
    // where {label} is like "Gen 3:24" (non-empty).
    // The checkbox anchor for the same verse has an empty label — we skip those.
    //
    // Strategy: find every non-empty-label /kjv/…/s_… anchor, record its byte
    // offset, then extract the text between that offset and the next such anchor
    // (or end of concordance section).

    preg_match_all(
        '/<a\b[^>]+href="\/kjv\/([a-z0-9]+)\/(\d+)\/(\d+)\/s_\d+"[^>]*>([^<]+)<\/a>/i',
        $section,
        $m,
        PREG_OFFSET_CAPTURE
    );

    if (empty($m[0])) return [];

    // De-duplicate: same book/ch/vs may appear twice (checkbox + label).
    // Keep only those with a proper citation label (contains a digit and colon).
    $refs = [];
    $seen = [];
    foreach ($m[0] as $idx => $match) {
        $label = trim($m[4][$idx][0]);
        // A proper citation label looks like "Gen 3:24" — contains colon
        if (!str_contains($label, ':')) continue;
        $abbr    = strtolower($m[1][$idx][0]);
        $chapter = $m[2][$idx][0];
        $verse   = $m[3][$idx][0];
        $key     = $abbr . $chapter . ':' . $verse;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $refs[] = [
            'abbr'    => $abbr,
            'chapter' => $chapter,
            'verse'   => $verse,
            'label'   => $label,
            'pos'     => $match[1],   // byte offset in $section
        ];
    }

    if (empty($refs)) return [];

    $secLen  = strlen($section);
    $entries = [];

    foreach ($refs as $i => $ref) {
        // End of this entry's chunk = start of next ref anchor, or end of section
        $chunkEnd = isset($refs[$i + 1]) ? $refs[$i + 1]['pos'] : $secLen;
        // Start just after the label anchor's closing </a>
        $anchorEnd = strpos($section, '</a>', $ref['pos']);
        if ($anchorEnd === false) continue;
        $anchorEnd += strlen('</a>');
        $chunk = substr($section, $anchorEnd, $chunkEnd - $anchorEnd);

        // Strip everything from "Tools" to end of chunk (entry terminator)
        // "Tools" appears as plain text at the very end of each verse block
        if (preg_match('/\bTools\b/i', $chunk, $toolsMatch, PREG_OFFSET_CAPTURE)) {
            $chunk = substr($chunk, 0, $toolsMatch[0][1]);
        }

        // Remove the leading " - " separator (from the markup immediately after the label anchor)
        $chunk = preg_replace('/^\s*[-–]\s*/u', '', $chunk);

        // ── Convert Strong's anchors ──────────────────────────────────────────
        // Pattern: some text node, then <a href="/lexicon/HNNNN/kjv/">HNNNN</a>
        // We want: for the target Strong's → append " [H2719](url)" to the
        //          preceding word; for others → just drop the anchor entirely.
        //
        // The anchor text IS the Strong's ID. The word is in the text node
        // immediately before the anchor (with optional punctuation between).

        $chunk = preg_replace_callback(
            '/(<a\b[^>]+href="[^"]*\/lexicon\/(H\d+|G\d+)\/[^"]*"[^>]*>(?:H|G)\d+<\/a>)/i',
            function (array $m) use ($target, $lexUrl, &$chunk): string {
                $id = '';
                if (preg_match('/\/lexicon\/(H\d+|G\d+)\//i', $m[1], $idm)) {
                    $id = strtoupper($idm[1]);
                }
                // For the target Strong's, keep as markdown link; otherwise drop
                return ($id === $target) ? " [{$id}]({$lexUrl})" : '';
            },
            $chunk
        );

        // ── Clean the text ────────────────────────────────────────────────────
        // Decode HTML entities (this also converts &#10148; ➔ to the arrow char)
        $text = html_entity_decode($chunk, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Strip all remaining HTML tags
        $text = strip_tags($text);
        // Remove the repeated verse label from the start of the text if it appears there.
        $text = ltrim($text);
        if (str_starts_with($text, $ref['label'])) {
            $text = substr($text, strlen($ref['label']));
            $text = preg_replace('/^[\s\-–—:]+/u', '', $text);
        }
        // Remove BLB's interlinear arrows: ➔ (U+2794) and similar arrow chars
        $text = preg_replace('/[\x{2192}-\x{21FF}\x{2794}\x{279C}\x{27A1}]/u', '', $text);
        // Also remove the plain ASCII "=>" that sometimes appears
        $text = str_replace('=>', '', $text);
        // Collapse whitespace (but preserve single spaces)
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = trim($text);
        // Remove double spaces that appear around stripped arrows
        $text = preg_replace('/ {2,}/', ' ', $text);

        $entries[] = [
            'abbr'    => $ref['abbr'],
            'chapter' => $ref['chapter'],
            'verse'   => $ref['verse'],
            'ref'     => $ref['label'],
            'kjv'     => $text,
        ];
    }

    return $entries;
}

function blbUrl(string $strong, int $page): string {
    return sprintf('https://www.blueletterbible.org/lexicon/%s/kjv/wlc/0-%d/',
        strtolower($strong), $page);
}

// Detect next-page link.
// BLB pagination is in the static HTML as anchors like:
//   href="/lexicon/h2719/kjv/wlc/0-2/"
function hasNextPage(string $html, string $strong, int $nextPage): bool {
    $pattern = '/href="\/lexicon\/' . preg_quote(strtolower($strong), '/') .
               '\/kjv\/wlc\/0-' . $nextPage . '\//i';
    return (bool)preg_match($pattern, $html);
}

// ── Arabic API ────────────────────────────────────────────────────────────────

function fetchArabicStrongs(string $version, string $book, string $ch, string $vs): array|false {
    $url  = 'https://service.arabicbible.com/api/bible/strong/' .
            $version . '/' . rawurlencode($book) . '/' . $ch . '/' . $vs;
    $body = httpGet($url, json: true);
    if ($body === false) return false;
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data)) return false;
    $words = [];
    foreach ($data as $item) {
        if (!is_array($item)) continue;
        // New API shape: { "word": "...", "numbers": [{"value":"H2719", ...}, ...] }
        $word = null;
        $strongs = [];
        if (isset($item['word'])) {
            $word = $item['word'];
            if (isset($item['numbers']) && is_array($item['numbers'])) {
                foreach ($item['numbers'] as $n) {
                    if (!is_array($n)) continue;
                    $val = $n['value'] ?? ($n['lexicon']['strongNumber'] ?? null);
                    if ($val !== null && $val !== '') $strongs[] = strtoupper(trim((string)$val));
                }
            }
        } else {
            // Fallback to previous field names
            $word = $item['ArabicWord']   ?? $item['arabicWord']   ?? $item['Word']   ?? $item['word']   ?? null;
            $s = $item['StrongNumber'] ?? $item['strongNumber'] ?? $item['Strong'] ?? $item['strong'] ?? '';
            if ($s !== '') $strongs[] = strtoupper(trim((string)$s));
        }
        if ($word === null) continue;
        // normalize unique strongs
        $strongs = array_values(array_filter(array_unique($strongs), function($v) { return $v !== ''; }));
        $words[] = [
            'word'    => (string)$word,
            'strong'  => $strongs[0] ?? '',
            'strongs' => $strongs,
        ];
    }
    return $words ?: false;
}

function buildArabicLine(array $words, string $target): string {
    $target = strtoupper($target);
    // Strip the letter prefix for numeric comparison (handles "2719" vs "H2719")
    $targetNum = ltrim(preg_replace('/^[HG]/i', '', $target), '0');
    $matched   = false;
    $parts     = [];
    foreach ($words as $w) {
        $ids = [];
        if (isset($w['strongs']) && is_array($w['strongs']) && count($w['strongs']) > 0) {
            $ids = $w['strongs'];
        } elseif (!empty($w['strong'])) {
            $ids = [$w['strong']];
        }
        $found = false;
        foreach ($ids as $id) {
            $id = strtoupper(trim((string)$id));
            $idNum = ltrim(preg_replace('/^[HG]/i', '', $id), '0');
            if ($id === $target || ($idNum !== '' && $idNum === $targetNum)) { $found = true; break; }
        }
        if (!$matched && $found) {
            $parts[] = '==' . $w['word'] . '==';
            $matched = true;
        } else {
            $parts[] = $w['word'];
        }
    }
    return implode(' ', $parts);
}

// Return true if the Arabic API tokens contain the requested Strong's id
function arabicHasTarget(array $words, string $target): bool {
    $target = strtoupper($target);
    $targetNum = ltrim(preg_replace('/^[HG]/i', '', $target), '0');
    foreach ($words as $w) {
        $ids = [];
        if (isset($w['strongs']) && is_array($w['strongs']) && count($w['strongs']) > 0) {
            $ids = $w['strongs'];
        } elseif (!empty($w['strong'])) {
            $ids = [$w['strong']];
        }
        foreach ($ids as $id) {
            $id = strtoupper(trim((string)$id));
            if ($id === $target) return true;
            $idNum = ltrim(preg_replace('/^[HG]/i', '', $id), '0');
            if ($idNum !== '' && $idNum === $targetNum) return true;
        }
    }
    return false;
}

function fetchPlainArabic(string $book, string $ch, string $vs): string {
    $url  = 'https://injeel.com/verse/vdv,kjv/' . rawurlencode($book) . '/' . $ch . '/' . $vs;
    $body = httpGet($url);
    if ($body === false) return '';
    if (preg_match('/<td[^>]*>\s*[\d۰-۹٠-٩]+\s+([\s\S]+?)\s*<\/td>/u', $body, $m)) {
        $t = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', strip_tags($t)));
    }
    return '';
}

// ── Main ──────────────────────────────────────────────────────────────────────

$tag = $dryRun ? ' [DRY RUN]' : '';
echo "╔══════════════════════════════════════════════════════╗\n";
echo "║  BLB Concordance Crawler  v4{$tag}\n";
echo "╚══════════════════════════════════════════════════════╝\n";
echo "Strong's : $strongsNum\n";
if (!$dryRun) echo "Output   : $outputFile\n";
echo "Arabic   : $arabicVer\n";
if ($limit) echo "Limit    : first $limit entries\n";
echo "\n";

// Phase 1 — crawl BLB
echo "Phase 1 — Crawling BLB concordance …\n";

$all      = [];
$page     = 1;
$stop     = false;
$seenRefs = [];

while (!$stop) {
    $url  = blbUrl($strongsNum, $page);
    echo "  Page $page: $url\n";
    $html = httpGet($url);
    if ($html === false) { echo "  Fetch failed — stopping.\n"; break; }

    $entries = parseBLBPage($html, $strongsNum);
    if (empty($entries)) { echo "  No entries parsed on page $page — stopping.\n"; break; }

    $newOnPage = 0;
    foreach ($entries as $e) {
        if (isset($seenRefs[$e['ref']])) {
            continue;
        }
        $seenRefs[$e['ref']] = true;
        $all[] = $e;
        $newOnPage++;
        if ($limit > 0 && count($all) >= $limit) { $stop = true; break; }
    }

    if ($newOnPage === 0) {
        echo "  Page $page returned no new entries — stopping.\n";
        break;
    }

    echo "  Parsed " . count($entries) . " entries (" . $newOnPage . " new, total so far: " . count($all) . ")\n";
    if ($stop) { echo "  Limit of $limit reached.\n"; break; }

    if (count($entries) < 50) {
        echo "  Last page detected (fewer than 50 entries) — stopping.\n";
        break;
    }

    $page++;
    usleep((int)($delaySeconds * 1_000_000));
}


$total = count($all);
echo "\nTotal entries: $total\n";
if ($total === 0) { fwrite(STDERR, "No entries — check Strong's number.\n"); exit(1); }

// NOTE: Do not exit after Phase 1 when in dry-run. Continue to Phase 2
// so that Arabic verses (with highlights using ==) are fetched and
// included in the dry-run output. File writing is skipped later when
// --dry-run is set.

// Phase 2 — fetch Arabic
echo "\nPhase 2 — Fetching Arabic verses …\n";

$lines    = [];
$apiFails = 0;

foreach ($all as $i => $e) {
    $n    = $i + 1;
    $book = toApiBook($e['abbr']);
    echo "  [$n/$total] {$e['ref']} … ";

    $words = fetchArabicStrongs($arabicVer, $book, $e['chapter'], $e['verse']);
    $hasStrongMatch = false;
    if ($words !== false && count($words) > 0) {
        $hasStrongMatch = arabicHasTarget($words, $strongsNum);
        $arabic = buildArabicLine($words, $strongsNum);
        echo "OK" . ($hasStrongMatch ? " (Strong match)\n" : " (no Strong in API)\n");
        if (!$hasStrongMatch) {
            // Separate instances without Strong match by appending a marker
            $arabic .= "\n_[No Strong match in Arabic]_";
        }
    } else {
        $apiFails++;
        echo "API failed, trying injeel.com … ";
        $plain  = fetchPlainArabic($book, $e['chapter'], $e['verse']);
        $arabic = $plain !== '' ? $plain : '_[Arabic verse not available]_';
        echo ($plain !== '' ? "OK (plain)\n" : "FAILED\n");
        if ($plain !== '') {
            // Do not apply heuristic highlights here; simply mark no Strong match
            $arabic .= "\n_[No Strong match in Arabic]_";
        }
    }

    $lines[] = $e['ref'];
    $lines[] = $e['kjv'];
    $lines[] = $arabic;
    $lines[] = '';          // blank line between entries

    if ($n < $total) usleep((int)($delaySeconds * 1_000_000));
}

// Write output
$md = implode("\n", $lines) . "\n";
if ($dryRun) {
    echo "\n── DRY RUN OUTPUT " . str_repeat('─', 40) . "\n\n";
    // Print the assembled markdown lines (includes Arabic with == highlights)
    echo $md;
    echo "── END ($total entries) ──\n";
    exit(0);
}

if (file_put_contents($outputFile, $md) === false) {
    fwrite(STDERR, "ERROR: Cannot write to '$outputFile'\n"); exit(1);
}

echo "\n✓ Done — $outputFile\n";
echo "  Entries : $total\n";
if ($apiFails > 0) echo "  Fallback: $apiFails verse(s) used plain HTML (no highlight).\n";
