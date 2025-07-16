# PageCSS Dokuwiki Plugin

Allows custom per-page CSS injection using `<pagecss>` blocks. Auto-supports Wrap plugin classes.

## Examples/Usage

Define page-specific CSS using the `<pagecss>...</pagecss>` tag:

```css
<pagecss>
.notice { 
    background: #fff3cd; 
    color: #856404; 
    padding: 15px; 
    border-radius: 8px; 
 }
</pagecss>
```

Then apply the class:

With Wrap Plugin:
```html
<wrap notice>Important Notice!</wrap>
```

With raw HTML:
```html
<span notice>Important Notice!</span>
```

The plugin will automatically generate equivalent `.wrap_notice` styles for use with the Wrap Plugin.

## Syntax

Wrap your CSS block in <pagecss>...</pagecss>:

```css
.highlight { background: #e0f7fa; padding: 10px; }
```

The plugin will inject this CSS into the HTML <head> for the page.

Multiple <pagecss> blocks are allowed
.wrap_* versions are auto-generated to support Wrap Plugin
No output is shown for <pagecss> blocks — they only inject CSS

## Configuration and Settings

This plugin requires no configuration if using the Wrap plugin.
