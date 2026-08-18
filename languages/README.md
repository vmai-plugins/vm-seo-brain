# Translations

`load_plugin_textdomain()` loads `.mo` files from this directory for the
`vm-seo-brain` text domain.

**Current state: the plugin is not yet translatable.** The header declares a
text domain and the loader runs, but no user-facing string is wrapped in a
gettext call, so there is nothing here for a translator to work from. Dropping
a `.mo` file in this directory today would have no effect.

Making it real is a mechanical pass over `admin/views/*.php` and the WP_Error
messages in `includes/`, wrapping strings in `esc_html__()` / `esc_attr__()` /
`__()` with the `vm-seo-brain` domain, then generating a `.pot`. It is
deliberately not bundled with the security and correctness work, because a
sweep touching every file makes those changes impossible to review.
