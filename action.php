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
 * Author: Your Name/Entity (or original author if known)
 * Date: 2023-10-27 (or original creation date)
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */

// Import necessary DokuWiki extension classes
use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

/**
 * Class action_plugin_pagecss
 *
 * This class extends DokuWiki's ActionPlugin to hook into specific DokuWiki
 * events for processing and injecting custom page-specific CSS.
 */
class action_plugin_pagecss extends ActionPlugin {

    /**
     * Registers the plugin's hooks with the DokuWiki event handler.
     *
     * This method is called by DokuWiki during plugin initialization.
     * It sets up which DokuWiki events this plugin will listen for and
     * which methods will handle those events.
     *
     * @param EventHandler $controller The DokuWiki event handler instance.
     */
    public function register(EventHandler $controller) {
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
     * Extracts CSS content from `<pagecss>...</pagecss>` blocks within a DokuWiki page.
     *
     * This method is triggered by the 'PARSER_CACHE_USE' event. It reads the raw
     * content of the current wiki page, finds all `<pagecss>` blocks, combines
     * their content, and stores it in the page's metadata. It also generates
     * `.wrap_classname` rules for any classes found in the embedded CSS.
     *
     * @param \Doku_Event $event The DokuWiki event object, containing page data.
     */
    public function handle_metadata(\Doku_Event $event) {
        global $ID; // Global variable holding the current DokuWiki page ID.

        // Sanitize the page ID to ensure it's safe for file system operations and metadata.
        $id = cleanID($ID);
        // Get the raw content of the current wiki page. This includes all wiki syntax.
        $text = rawWiki($id);

        // Use a regular expression to find all occurrences of <pagecss>...</pagecss> blocks.
        // The /s modifier makes the dot (.) match newlines as well, allowing multiline CSS.
        // The (.*?) captures the content between the tags non-greedily.
        preg_match_all('/<pagecss>(.*?)<\/pagecss>/s', $text, $matches);

        // Check if any <pagecss> blocks were found.
        if (!empty($matches[1])) {
            // If blocks are found, combine all captured CSS content into a single string.
            // trim() is used to remove leading/trailing whitespace from each block.
            $styles = implode(" ", array_map('trim', $matches[1]));

            // If there's actual CSS content after trimming and combining.
            if ($styles) {
                $extra = ''; // Initialize a variable to hold the generated .wrap_classname styles.

                // Find all CSS class selectors (e.g., .myclass) within the extracted styles.
                // This regex captures the class name (e.g., 'myclass').
                preg_match_all('/\.([a-zA-Z0-9_-]+)\s*\{[^}]*\}/', $styles, $class_matches);

                // Iterate over each found class name.
                foreach ($class_matches[1] as $classname) {
                    // Construct a regex pattern to find the full CSS rule for the current class.
                    $pattern = '/\.' . preg_quote($classname, '/') . '\s*\{([^}]*)\}/';
                    // Match the specific class rule in the combined styles.
                    if (preg_match($pattern, $styles, $style_block)) {
                        // Extract the content of the CSS rule (e.g., "color: red; font-size: 1em;").
                        $css_properties = $style_block[1];

                        // Basic check to avoid malformed or incomplete styles that might contain
                        // unclosed braces, which could lead to invalid CSS.
                        if (strpos($css_properties, '{') === false && strpos($css_properties, '}') === false) {
                            // Append the generated .wrap_classname rule to the $extra string.
                            // DokuWiki often wraps user content in divs with classes like .wrap_someclass.
                            // This ensures that custom CSS can target these wrapped elements.
                            $extra .= ".wrap_$classname {{$css_properties}}\n";
                        }
                    }
                }

                // Append the generated .wrap_classname styles to the main $styles string.
                $styles .= "\n" . trim($extra);

                // IMPORTANT: Prevent premature closing of the <style> tag in the HTML output.
                // If a user accidentally or maliciously types `</style>` inside `<pagecss>`,
                // this replaces it with `<\style>` which is still valid CSS but doesn't close the tag.
                $styles = str_replace('</', '<\/', $styles);

                // Store the processed CSS styles in the page's metadata.
                // This makes the styles available later when the HTML header is generated.
                p_set_metadata($id, ['pagecss' => ['styles' => $styles]]);

                // Invalidate the DokuWiki parser cache for this page whenever its content changes.
                // This ensures that if the <pagecss> blocks are modified, the metadata is re-extracted.
                $event->data['depends']['page'][] = $id;

                return; // Exit the function as styles were found and processed.
            }
        }

        // If no <pagecss> blocks were found or they were empty,
        // ensure the 'pagecss' metadata entry is reset to an empty string.
        // This prevents old CSS from being injected if the blocks are removed.
        p_set_metadata($id, ['pagecss' => ['styles' => '']]);
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
    public function inject_css(Doku_Event $event) {
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
