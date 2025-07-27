<?php
/**
 * DokuWiki Plugin pagecss
 *
 * This file defines the syntax component of the 'pagecss' DokuWiki plugin.
 * It is responsible for recognizing and parsing custom CSS blocks embedded
 * directly within DokuWiki pages using the `<pagecss>...</pagecss>` tags.
 *
 * While this syntax plugin captures the CSS content, it does NOT directly
 * output it to the HTML. The actual injection of this CSS into the
 * `<head>` section of the DokuWiki page is handled by the `action.php`
 * component of this plugin (specifically, `action_plugin_pagecss`).
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  dWiGhT Mulcahy <dWiGhT.Codes@gmail.com>
 */

// Ensure DokuWiki is loaded and prevent direct access to the plugin file.
// DOKU_INC is a constant defined by DokuWiki, representing its installation directory.
// If DOKU_INC is not defined, it means the script is being accessed directly,
// which is not allowed for DokuWiki plugins.
if (!defined('DOKU_INC')) {
    die();
}

/**
 * Class syntax_plugin_pagecss
 *
 * This class extends DokuWiki_Syntax_Plugin, providing the necessary
 * methods to define a custom syntax for embedding CSS within DokuWiki pages.
 * It's responsible for:
 * 1. Defining the type of syntax (container).
 * 2. Specifying the block-level nature of the syntax.
 * 3. Setting its processing priority.
 * 4. Connecting its entry and exit patterns to the DokuWiki lexer.
 * 5. Handling the data parsed by the lexer (capturing the CSS content).
 * 6. (Optionally) Rendering output, though for this plugin, rendering is minimal
 * as the CSS is passed to an action plugin for actual injection.
 */
class syntax_plugin_pagecss extends DokuWiki_Syntax_Plugin {

    /**
     * Defines the type of syntax plugin.
     *
     * 'container' means this plugin has distinct opening and closing tags,
     * and it encapsulates content between them (e.g., `<pagecss>...</pagecss>`).
     * Other common types include 'substition' (self-closing tags), 'protected'
     * (content not parsed by DokuWiki), 'formatting' (inline text formatting), etc.
     *
     * @return string 'container'
     */
    public function getType() {
        return 'container';
    }

    /**
     * Defines the paragraph type.
     *
     * 'block' indicates that this syntax element creates a block-level element.
     * This means it cannot be part of an existing paragraph and will typically
     * force a new line before and after its content in the parsed output.
     * This is appropriate for CSS blocks which are not meant to be inline text.
     *
     * @return string 'block'
     */
    public function getPType() {
        return 'block';
    }

    /**
     * Defines the sort order (priority) for syntax plugins.
     *
     * DokuWiki processes syntax plugins in ascending order of their sort value.
     * A higher number means the plugin is processed later. This plugin is given
     * a relatively high number (199). This ensures that it runs after most
     * other common DokuWiki syntax elements have been processed, allowing it
     * to capture raw text that might otherwise be interpreted by other plugins.
     *
     * @return int The sort order value (e.g., 199).
     */
    public function getSort() {
        return 199;
    }

    /**
     * Connects the plugin's syntax patterns to the DokuWiki lexer.
     *
     * This method is crucial for telling DokuWiki how to identify the start
     * of this plugin's custom syntax within the wiki text.
     *
     * @param string $mode The current DokuWiki lexer mode (e.g., 'base', 'p_wiki').
     * Plugins often connect to 'base' mode to be available everywhere.
     */
    public function connectTo($mode) {
        // Adds an entry pattern for the '<pagecss>' opening tag.
        // The regular expression '<pagecss>(?=.*?</pagecss>)' is used:
        // - '<pagecss>': Matches the literal string "<pagecss>".
        // - (?=.*?</pagecss>): This is a positive lookahead assertion.
        //   - `(?=...)`: Asserts that the characters immediately following the current position
        //                  match the pattern inside the lookahead, but these characters are NOT
        //                  consumed by this match. This means the lexer won't advance past `<pagecss>`
        //                  based on the lookahead part.
        //   - `.*?`: Matches any character (`.`) zero or more times (`*`) in a non-greedy way (`?`).
        //            This ensures it matches the shortest possible string until the next part.
        //   - `</pagecss>`: Matches the literal closing tag.
        // This lookahead ensures that the '<pagecss>' tag is only recognized as a valid entry
        // pattern if it is eventually followed by a matching closing '</pagecss>' tag.
        // 'plugin_pagecss' is the name of the new lexer state (or mode) that DokuWiki enters
        // when this pattern is matched. All content until the exit pattern will be processed
        // within this 'plugin_pagecss' state.
        $this->Lexer->addEntryPattern('<pagecss>(?=.*?</pagecss>)',$mode,'plugin_pagecss');
    }

    /**
     * Defines how the plugin's syntax pattern exits the current state.
     *
     * This method specifies the closing tag for this 'container' type plugin.
     * When the lexer is in the 'plugin_pagecss' state and encounters this pattern,
     * it will exit that state and return to the previous lexer mode.
     */
    public function postConnect() {
        // Adds an exit pattern for '</pagecss>'.
        // When this pattern is encountered while the lexer is in the 'plugin_pagecss' state,
        // it signifies the end of the custom CSS block.
        $this->Lexer->addExitPattern('</pagecss>', 'plugin_pagecss');
    }

    /**
     * Handles the matched syntax.
     *
     * This method is called by the DokuWiki parser when the lexer identifies
     * content related to this plugin's defined syntax patterns. Its primary
     * role is to process the raw matched text and transform it into a
     * structured data representation that can be used by the renderer or other
     * parts of the plugin (like the action plugin).
     *
     * @param string        $match   The raw text that was matched by the lexer (e.g., "<pagecss>", "body { color: red; }", "</pagecss>").
     * @param int           $state   The current state of the lexer when the match occurred.
     * Possible states include:
     * - DOKU_LEXER_ENTER: The opening tag was matched (e.g., '<pagecss>').
     * - DOKU_LEXER_UNMATCHED: Content between the entry and exit patterns (the actual CSS).
     * - DOKU_LEXER_EXIT: The closing tag was matched (e.g., '</pagecss>').
     * @param int           $pos     The byte position of the match within the original text.
     * @param Doku_Handler  $handler The DokuWiki handler object. This object is used to
     * manipulate the parser's instruction stream (e.g., adding instructions).
     * @return array|bool   Returns an array containing the captured CSS content if the state is
     * DOKU_LEXER_UNMATCHED, otherwise returns true to indicate successful handling
     * of the enter/exit states (no specific data needed for those).
     */
    public function handle($match, $state, $pos, Doku_Handler $handler){
        // This condition checks if the lexer is processing the actual content
        // *between* the opening and closing tags. This is where the user's CSS code resides.
        if ($state === DOKU_LEXER_UNMATCHED) {
            // Return an associative array where the key 'css' holds the matched content.
            // This data will then be passed to the `render` method of this plugin.
            return ['css' => $match];
        }
        // For the DOKU_LEXER_ENTER (opening tag) and DOKU_LEXER_EXIT (closing tag) states,
        // no specific data needs to be captured by the syntax plugin itself,
        // so we simply return `true` to acknowledge that the state transition was handled.
        return true;
    }

    /**
     * Renders the handled data into the DokuWiki output.
     *
     * This method is called by the DokuWiki renderer to generate the final HTML
     * or other output format (e.g., XHTML, ODT).
     *
     * For the 'pagecss' plugin, this `render` method primarily serves for
     * debugging purposes. The actual injection of the CSS into the page's
     * `<head>` section is NOT done here. Instead, the CSS is captured by
     * the `handle()` method and then typically retrieved and processed by an
     * accompanying action plugin (defined in `action.php`) which listens to
     * DokuWiki events (e.g., `TPL_METAHEADER_OUTPUT`) to insert the CSS
     * at the correct location in the HTML document.
     *
     * @param string        $mode     The rendering mode (e.g., 'xhtml', 'odt').
     * @param Doku_Renderer $renderer The DokuWiki renderer object, used to output HTML, etc.
     * @param mixed         $data     The data returned by the `handle()` method. For this plugin,
     * it's an array like `['css' => '...']` when processing the CSS content.
     * @return bool     Always returns true, indicating that the rendering process for this
     * syntax element has completed successfully, even if no visible output is produced.
     */
    public function render($mode, Doku_Renderer $renderer, $data) {
        // Check if the received data is an array, which signifies that it contains
        // the CSS content captured during the DOKU_LEXER_UNMATCHED state in `handle()`.
        if (is_array($data)) {
            // Log the captured CSS content to the DokuWiki debug log.
            // This is useful for verifying that the plugin correctly extracts the CSS.
            // `substr($data['css'], 0, 200)` truncates the CSS string to the first
            // 200 characters to prevent excessively long entries in the debug log.
            dbglog("pagecss plugin: Captured CSS (first 200 chars): " . substr($data['css'], 0, 200));

            // IMPORTANT NOTE: This `render` method DOES NOT output the CSS directly
            // to the HTML page's `<body>`. If it did, the CSS would not be applied
            // correctly and would likely be visible as text on the page.
            // The sole purpose of this `syntax.php` file is to identify, parse,
            // and capture the CSS content. The `action.php` file (the action plugin)
            // is responsible for retrieving this captured CSS and injecting it into
            // the `<head>` section of the HTML.
        }
        // Always return true to signal that the rendering process for this syntax element
        // has finished successfully.
        return true;
    }
}
