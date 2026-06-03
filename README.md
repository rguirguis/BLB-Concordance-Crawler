# BLB Concordance Crawler  v2

A single-file PHP 8.x script that produces a Markdown concordance file with:

- Every KJV verse containing a given Strong's number (all pages crawled automatically)
- The English word linked to the Strong's entry: `sword [H2719](https://…)`
- The full Arabic verse (Van Dyke Version or NAV)
- The exact Arabic word highlighted: `==سَيْفٍ==`

The Arabic highlighting uses the **same JSON API** that [injeel.com](https://injeel.com)'s
Angular app calls internally when you toggle the Strong's numbers button —
`service.arabicbible.com/api/bible/strong/{version}/{book}/{chapter}/{verse}`.
This gives a per-word `{ArabicWord, StrongNumber}` array, so the correct word is
found precisely, with no guessing or root-matching tables.

---

## Requirements

- **PHP 8.x** (you have 8.4 ✓) with `curl` and `mbstring` extensions
- Both are enabled by default in PHP 8.4 on Windows
- Verify: `php -m | grep -E "curl|mbstring"`

---

## Usage

Open **Git Bash** and run:

```bash
php blb_concordance_crawler.php --help
```

or use any combination of supported options:

```bash
php blb_concordance_crawler.php --strong H2719 --output sword.md --version vdv
```

| Option | Default | Notes |
|--------|---------|-------|
| `--strong HNNNN` | `H2719` | Any Hebrew (H…) or Greek (G…) Strong's number |
| `--output FILE` | `concordance.md` | Output file path |
| `--version STR` | `vdv` | Arabic Bible version: `vdv` (Van Dyke) or `nav` |
| `--limit N` | `0` | Process only first N entries (0 = all) |
| `--dry-run` | off | Print parsed KJV entries only; no Arabic fetch, no output file |
| `--help`, `-h` | off | Show help and exit |

**Examples:**

```bash
# H2719 (sword) with all defaults
php blb_concordance_crawler.php

# Explicit args
php blb_concordance_crawler.php H2719 h2719_sword.md vdv

# A different Strong's number
php blb_concordance_crawler.php H3068 yhwh.md vdv
php blb_concordance_crawler.php G26 agape_nt.md nav
```

---

## How It Works

```
BLB page 1..N  ──parse──▶  [ref, KJV verse with H2719 linked]
                                         │
                                         ▼
service.arabicbible.com/api/bible/strong/vdv/Genesis/3/24
  ──returns──▶  [ {ArabicWord:"فَطَرَدَ", StrongNumber:"H1644"},
                  {ArabicWord:"الإِنْسَانَ،", StrongNumber:"H120"},
                  ...
                  {ArabicWord:"سَيْفٍ", StrongNumber:"H2719"},  ← match!
                  ... ]
                                         │
                                         ▼
                              ==سَيْفٍ==  highlighted in output
```

If the Strong's API is unavailable for a verse, the script falls back to
fetching the plain Arabic verse from `injeel.com/verse/vdv,kjv/…` — the same
URL you'd open manually. No Strong's highlight is applied in that case.

---

## Output Format

```markdown
### Gen 3:24

So he drove out the man; and he placed at the east of the garden of Eden Cherubims,
and a flaming sword [H2719](https://www.blueletterbible.org/lexicon/H2719/kjv/)
which turned every way, to keep the way of the tree of life.

فَطَرَدَ الإِنْسَانَ، وَأَقَامَ شَرْقِيَّ جَنَّةِ عَدْنٍ الْكَرُوبِيمَ، وَلَهِيبَ ==سَيْفٍ== مُتَقَلِّبٍ لِحِرَاسَةِ طَرِيقِ شَجَرَةِ الْحَيَاةِ.

---
```

The `==…==` highlight renders in **Obsidian**, **Typora**, GitHub, and most
modern Markdown renderers. In VS Code you may need the "Markdown Highlight" extension.

---

## Runtime Estimate

| Strong's | Occurrences | Approx. time |
|----------|------------|--------------|
| H2719 (sword) | 413 | ~17 min |
| H3068 (YHWH)  | 6,828 | ~2.5 hours |
| G26 (agapē)   | 116 | ~5 min |

The 1.2s delay between requests is intentional to avoid rate-limiting both sites.
You can reduce `$delaySeconds` to `0.5` for faster runs if you accept the risk.

---

## Troubleshooting

**PHP says `curl` is not available:**
```ini
; In your php.ini (usually C:\php\php.ini):
extension=curl
extension=mbstring
```

**Arabic text is garbled in Notepad:**  
The file is UTF-8. Open it in VS Code, Obsidian, or another UTF-8 aware editor.

**Some verses show "no Strong's highlight — API unavailable":**  
The `service.arabicbible.com` API may not have Strong's tagging for every book/version.
Deuterocanonical books and very short NT books are sometimes missing.
The plain Arabic verse is still shown via injeel.com fallback.

**A verse is missing entirely:**  
BLB and injeel.com may number some books differently (e.g. Psalms vs Psalm).
Check the `BOOK_MAP` constant in the script and adjust if needed.
