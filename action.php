<?php
/**
 * DokuWiki Plugin: pagecss
 *
 * This plugin allows DokuWiki users to embed custom CSS directly within their
 * wiki pages using `<pagecss>...</pagecss>` blocks. The CSS defined within
 * these blocks is then extracted, processed, and injected into the `<head>`
 * section of the generated HTML page.
 *
 * It also provides a feature to automatically wrap CSS rules for classes
 * found within the `<pagecss>` block (e.g., `.myclass { ... }`) with a
 * `.wrap_myclass { ... }` equivalent. This is useful for styling elements
 * that are automatically wrapped by DokuWiki's `.wrap` classes.
 *
 * Author: dWiGhT Mulcahy

 * Date: 2023-10-27 (or original creation date)
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */

// Import necessary DokuWiki extension classes
use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

// --- Tidy CSS Integration ---
require_once __DIR__ . '/vendor/csstidy-2.2.1/class.csstidy.php';
// --- End Tidy CSS Integration ---

/**
 * Class action_plugin_pagecss
 *
 * This class extends DokuWiki's ActionPlugin to hook into specific DokuWiki
 * events for processing and injecting custom page-specific CSS.
 */
class action_plugin_pagecss extends ActionPlugin
{

    /**
     * Store the HTMLPurifier instance. We initialize it once to avoid repeated overhead.
     * @var HTMLPurifier|null
     */
    private $purifier = null;

    /**
     * Registers the plugin's hooks with the DokuWiki event handler.
     *
     * This method is called by DokuWiki during plugin initialization.
     * It sets up which DokuWiki events this plugin will listen for and
     * which methods will handle those events.
     *
     * @param EventHandler $controller The DokuWiki event handler instance.
     */
    public function register(EventHandler $controller)
    {

        // Register a hook to inject custom CSS into the HTML header.
        // 'TPL_METAHEADER_OUTPUT' is triggered just before the <head> section is closed.
        // 'BEFORE' ensures our CSS is added before other elements that might rely on it.
        // '$this' refers to the current plugin instance.
        // 'inject_css' is the method that will be called when this event fires.
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'inject_css');

        // Register a hook to handle metadata caching and extraction of CSS.
        // 'PARSER_CACHE_USE' is triggered before DokuWiki attempts to use its parser cache.
        // 'BEFORE' allows us to modify the metadata before the page is rendered or cached.
        // 'handle_metadata' is the method that will be called.
        $controller->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, 'handle_metadata');
    }

    /**
     * Sanitize user-provided CSS using CSSTidy and additional filtering.
     *
     * @param string $css_input Raw user CSS inside <pagecss> block
     * @return string Cleaned, safe CSS or an empty string if invalid
     */
    /**
     * Sanitize user-provided CSS using CSSTidy and additional filtering
     * to prevent CSS-based XSS and injection attacks.
     *
     * @param string $css Raw user CSS inside <pagecss> block
     * @return string Cleaned, safe CSS or empty string if invalid or dangerous
     */
    private function sanitizeCSS($css) {
        dbglog("pagecss: raw\n$css", 2);

        // Bail if too many CSS blocks (basic sanity check)
        if (substr_count($css, '{') > 100) {
            dbglog("pagecss: too many CSS blocks", 2);
            return '';
        }

        // Initialize CSSTidy and configure for safe cleanup
        $tidy = new csstidy();
        $tidy->set_cfg('remove_bslash', true);
        $tidy->set_cfg('compress_colors', true);
        $tidy->set_cfg('compress_font-weight', true);
        $tidy->set_cfg('lowercase_s', true);
        $tidy->set_cfg('optimise_shorthands', 1);
        $tidy->parse($css);

        $tidy_css = $tidy->print->plain();
        dbglog("pagecss: tidy\n$tidy_css", 2);

        // Bail if output is suspiciously long
        if (strlen($tidy_css) > 5000) {
            dbglog("pagecss: too long after tidy", 2);
            return '';
        }

        // Further harden CSS by blocking dangerous patterns:
        $patterns = [
            '/expression\s*\(.*?\)/i', // - CSS expressions (IE-only)
            '/url\s*\(\s*[\'"]?\s*javascript:/i', // - javascript: URLs in url()
            '/behavior\s*:/i', // - behavior property (IE)
            '/-moz-binding\s*:/i', // - -moz-binding property (Firefox)
            '/url\s*\(\s*[\'"]?\s*data:text\/html/i', // - data:text/html URLs (potential script injection)
            '/@import/i', // - @import rules
            '/unicode-range/i', // - unicode-range declarations (can hide obfuscated code)
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $tidy_css)) {
                dbglog("pagecss: blocked dangerous CSS pattern: $pattern", 2);
                return ''; // Reject entire CSS block if dangerous pattern found
            }
        }

        // Remove control characters which may cause issues
        $tidy_css = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $tidy_css);

        dbglog("pagecss: sanitized\n$tidy_css", 2);
        return $tidy_css;
    }

    /**
     * Extracts CSS content from `<pagecss>...</pagecss>` blocks within a DokuWiki page.
     *
     * This method is triggered by the 'PARSER_CACHE_USE' event. It reads the raw
     * content of the current wiki page, finds all `<pagecss>` blocks, and combines
     * their content, and stores it in the page's metadata. It also generates
     * `.wrap_classname` rules for any classes found in the embedded CSS.
     *
     * @param \Doku_Event $event The DokuWiki event object, containing page data.
     */
    public function handle_metadata(\Doku_Event $event)
    {
        global $ID;

        $id = cleanID($ID);
        $text = rawWiki($id);

        // Sanitize the page ID to ensure it's safe for file system operations and metadata.
        $id = cleanID($ID);
        // Get the raw content of the current wiki page. This includes all wiki syntax.
        $text = rawWiki($id);

        // Use a regular expression to find all occurrences of <pagecss>...</pagecss> blocks.
        // The /s modifier makes the dot (.) match newlines as well, allowing multiline CSS.
        // The (.*?) captures the content between the tags non-greedily.
        preg_match_all('/<pagecss>(.*?)<\/pagecss>/s', $text, $matches);

        if (!empty($matches[1])) {
            $styles_raw = implode(" ", array_map('trim', $matches[1]));

            if ($styles_raw) {
                $sanitized_css = $this->sanitizeCSS($styles_raw);

                if (!$sanitized_css) {
                    dbglog("pagecss: CSS sanitized to empty for $ID", 2);
                    p_set_metadata($id, ['pagecss' => ['styles' => '']]);
                    return;
                }

                dbglog("pagecss: sanitized CSS ready for $ID", 2);

                $extra = '';
                preg_match_all('/\.([a-zA-Z0-9_-]+)\s*\{[^}]*\}/', $sanitized_css, $class_matches);

                if (!empty($class_matches[1])) {
                    dbglog("pagecss: found class selectors: " . implode(', ', $class_matches[1]), 2);
                }

                foreach ($class_matches[1] as $classname) {
                    // Construct a regex pattern to find the full CSS rule for the current class.
                    $pattern = '/\.' . preg_quote($classname, '/') . '\s*\{([^}]*)\}/';
                    if (preg_match($pattern, $sanitized_css, $style_block)) {
                        $css_properties = $style_block[1];
                        if (strpos($css_properties, '{') === false && strpos($css_properties, '}') === false) {
                            $extra .= ".wrap_$classname {{$css_properties}}\n";
                        }
                    }
                }

                $styles = $sanitized_css . "\n" . trim($extra);
                $styles = str_replace('</', '<\/', $styles);

                p_set_metadata($id, ['pagecss' => ['styles' => $styles]]);
                dbglog("pagecss: styles stored in metadata for $id", 2);
                return;
            }
        }

        // Clear styles if none found
        p_set_metadata($id, ['pagecss' => ['styles' => '']]);
        dbglog("pagecss: no <pagecss> blocks found, clearing styles for $id", 2);
    }

    /**
     * Injects the extracted CSS into the HTML `<head>` section of the DokuWiki page.
     *
     * This method is triggered by the 'TPL_METAHEADER_OUTPUT' event. It retrieves
     * the stored CSS from the page's metadata and adds it to the event data,
     * which DokuWiki then uses to build the `<head>` section.
     *
     * @param Doku_Event $event The DokuWiki event object, specifically for metaheader output.
     */
    public function inject_css(\Doku_Event $event)
    {
        global $ID; // Global variable holding the current DokuWiki page ID.

        // Sanitize the page ID.
        $id = cleanID($ID);

        // Retrieve the 'pagecss' metadata for the current page.
        $data = p_get_metadata($id, 'pagecss');
        // Extract the 'styles' content from the metadata, defaulting to an empty string if not set.
        $styles = isset($data['styles']) ? $data['styles'] : '';

        // Check if there are valid styles to inject and ensure it's a string.
        if ($styles && is_string($styles)) {
            // Add the custom CSS to the event's 'style' array.
            // DokuWiki's template system will then automatically render this
            // as a <style> block within the HTML <head>.
            $event->data['style'][] = [
                'type' => 'text/css', // Specifies the content type.
                'media' => 'screen',  // Specifies the media type for the CSS (e.g., 'screen', 'print').
                '_data' => $styles,   // The actual CSS content.
            ];
        }
    }

}
