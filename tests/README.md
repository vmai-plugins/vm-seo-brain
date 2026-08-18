# Tests

Plain PHP, no framework, no WordPress bootstrap. Run from the plugin root:

```bash
php tests/test-link-inserter.php
```

Exit code is 0 when everything passes, 1 otherwise, so this drops straight into
CI or a pre-commit hook.

## Why this file and not others

`VMSB_Link_Inserter` is the only code in the plugin that rewrites somebody
else's post content. Everything else can be re-run; a corrupted post cannot.
The cases here are the exact content shapes that broke the `preg_replace` this
class replaced:

- the anchor phrase appearing first inside an existing `<a>` (nested anchors)
- the phrase appearing inside an `alt=""` or `title=""` attribute
- the phrase appearing inside Gutenberg block-comment JSON
- the phrase appearing inside a shortcode argument
- the phrase appearing only inside a heading
- entity and whitespace mismatches between what the model returns and what the
  content actually holds (`R&D` vs `R&amp;D`, straight vs curly apostrophes,
  a phrase broken across a newline)
- word-boundary matches (`cost` inside `costume`)

The parsing core is deliberately free of WordPress dependencies apart from
`wp_parse_url()` and `untrailingslashit()`, which is what makes testing it this
cheaply possible. Keep it that way: anything that needs `get_post()` or the
database belongs in `insert()`, not in `locate()` or `protected_ranges()`.
