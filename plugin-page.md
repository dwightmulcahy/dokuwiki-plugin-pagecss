====== pagecss Plugin ======

---- plugin ----
description: Inject per-page custom CSS using <css>...</css> blocks. Auto-supports Wrap plugin classes.
author     : dWiGhT Mulcahy  
email      : 
type       : syntax
lastupdate : 2025-07-16
compatible : 2020-07-29 "Hogfather" and later
depends    : 
conflicts  : 
similar    : plugin:cssperpage
tags       : css, wrap, style, theme, syntax

downloadurl: https://github.com/dwightmulcahy/dokuwiki-plugin-pagecss/archive/refs/heads/main.zip
bugtracker : https://github.com/dwightmulcahy/dokuwiki-plugin-pagecss/issues
sourcerepo : https://github.com/dwightmulcahy/dokuwiki-plugin-pagecss/
donationurl: 

screenshot_img : 
----

//:!: This plugin allows users to style individual pages using inline `<css>` blocks without editing the site template. Useful for per-page banners, notices, or custom layouts.//

===== Installation =====

Install via the [[plugin:extension|Extension Manager]] or manually:

  * Download the ZIP from the link above
  * Extract it into `lib/plugins/pagecss/`
  * Make sure `syntax.php` and `plugin.info.txt` are in place

No configuration needed if using the `Wrap` plugin, it works immediately after activation.
Using the `<div ...>` requires a DocuWiki configuration setting to allow raw HTML.

===== Examples/Usage =====

Define page-specific CSS using the `<css>...</css>` tag:

<code>
<css>
.notice {
  background: #fff3cd;
  color: #856404;
  padding: 15px;
  border-radius: 8px;
}
</css>
</code>

Then apply the class:

  * With **Wrap Plugin**:
    <code>
    <WRAP notice>
    ⚠️ Important notice here.
    </WRAP>
    </code>

  * With raw HTML (if `htmlok` is enabled):
    <code html>
    <div class="notice">⚠️ Important notice here.</div>
    </code>

The plugin will automatically generate equivalent `.wrap_notice` styles for use with the Wrap Plugin.

===== Syntax =====

Wrap your CSS block in `<css>...</css>`:

<code>
<css>
.highlight {
  background: #e0f7fa;
  padding: 10px;
}
</css>
</code>

The plugin will inject this CSS into the HTML `<head>` for the page.

  * Multiple `<css>` blocks are allowed
  * `.wrap_*` versions are auto-generated to support Wrap Plugin
  * No output is shown for `<css>` blocks — they only inject CSS

===== Configuration and Settings =====

This plugin requires no configuration if using the `Wrap` plugin.

To use raw `<div class="...">` HTML, enable raw HTML rendering:

In `conf/local.php`:
<code php>
$conf['htmlok'] = 1;
</code>

And make sure your user account has permission to use raw HTML.

===== Development =====

The source code is available at:

  * GitHub: [https://github.com/example/dokuwiki-plugin-pagecss](https://github.com/dwightmulcahy/dokuwiki-plugin-pagecss)

=== Changelog ===

{{rss>https://github.com/dwightmulcahy/dokuwiki-plugin-pagecss/commits/main.atom date 8}}

=== Known Bugs and Issues ===

  * Wrap class generation duplicates entire blocks — can be optimized
  * No validation on CSS (invalid styles will be silently injected)

=== ToDo/Wish List ===

  * Add support for per-namespace CSS
  * Option to minify output CSS
  * Admin option to disable raw `<div>` styling

===== FAQ =====

**Q:** Does this plugin affect other pages?\\ 
**A:** No. The CSS is injected only into the page that includes the `<css>` block.

**Q:** Do I need the Wrap plugin?\\ 
**A:** No, but if you use Wrap syntax, this plugin ensures your styles apply to `.wrap_*` classes automatically.

**Q:** What if I use the same class name on two pages?\\ 
**A:** That's fine — each page injects its own CSS. There’s no global conflict.
