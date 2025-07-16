<?php
/**
 * DokuWiki Plugin pagecss
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  dWiGhT Mulcahy <dWiGhT.Codes@gmail.com>
 */

// Ensure DokuWiki is loaded and prevent direct access to the plugin file.
// DOKU_INC is a constant defined by DokuWiki, representing its installation directory.
if (!defined('DOKU_INC')) {
    die();
}

/**
 * Class syntax_plugin_pagecss
 *
 * This class defines the syntax for the 'pagecss' DokuWiki plugin.
 * It allows users to embed custom CSS directly within a DokuWiki page
 * using <pagecss> tags. The plugin captures the content within these
 * tags but doesn't directly render it into the HTML output in the 'render' method.
 * Instead, it's expected that another part of the plugin (e.g., an event handler)
 * would retrieve and process this captured CSS to inject it into the page's
 * <head> section or similar.
 */
class syntax_plugin_pagecss extends DokuWiki_Syntax_Plugin {

    /**
     * Defines the type of syntax plugin.
     *
     * 'container' means this plugin has opening and closing tags (e.g., <pagecss>...</pagecss>).
     * Other common types include 'substition' (self-closing tags), 'protected' (content not parsed), etc.
     *
     * @return string 'container'
     */
    public function getType() {
        return 'container';
    }

    /**
     * Defines the paragraph type.
     *
     * 'block' indicates that this syntax element creates a block-level element,
     * meaning it cannot be part of an existing paragraph and will typically start
     * on a new line.
     *
     * @return string 'block'
     */
    public function getPType() {
        return 'block';
    }

    /**
     * Defines the sort order for syntax plugins.
     *
     * Plugins are processed in ascending order of their sort value.
     * A higher number means it's processed later. This plugin is given
     * a relatively high number (199), suggesting it should be processed
     * after many other common DokuWiki syntax elements.
     *
     * @return int The sort order.
     */
    public function getSort() {
        return 199;
    }

    /**
     * Connects the plugin's syntax patterns to the DokuWiki lexer.
     *
     * This method defines how the DokuWiki parser identifies the start of
     * this plugin's syntax.
     *
     * @param string $mode The current DokuWiki lexer mode (e.g., 'base', 'p_wiki').
     */
    public function connectTo($mode) {
        // Adds an entry pattern for '<pagecss>' tag.
        // '<pagecss>(?=.*?</pagecss>)' is a regular expression:
        // - '<pagecss>': Matches the literal string "<pagecss>".
        // - (?=.*?</pagecss>): This is a positive lookahead assertion.
        //   - (?=...): Asserts that the following characters match the pattern inside, but
        //              do not consume them.
        //   - .*: Matches any character (except newline) zero or more times.
        //   - ?: Makes the `.*` non-greedy, so it matches the shortest possible string.
        //   - </pagecss>: Matches the literal closing tag.
        // This ensures that the '<pagecss>' tag is only recognized as an entry pattern
        // if it is followed by a closing '</pagecss>' tag somewhere later in the text,
        // making it a valid container.
        // 'plugin_pagecss' is the name of the state (or mode) that the lexer enters
        // when this pattern is matched.
        $this->Lexer->addEntryPattern('<pagecss>(?=.*?</pagecss>)',$mode,'plugin_pagecss');
    }

    /**
     * Defines how the plugin's syntax pattern exits the current state.
     *
     * This method specifies the closing tag for the 'container' type plugin.
     */
    public function postConnect() {
        // Adds an exit pattern for '</pagecss>'.
        // When this pattern is encountered while in the 'plugin_pagecss' state,
        // the lexer exits that state.
        $this->Lexer->addExitPattern('</pagecss>', 'plugin_pagecss');
    }

    /**
     * Handles the matched syntax.
     *
     * This method is called by the DokuWiki parser when the lexer encounters
     * content related to this plugin's syntax. It processes the raw matched
     * text and returns a structured data representation.
     *
     * @param string        $match   The text that was matched by the lexer.
     * @param int           $state   The current state of the lexer (e.g., DOKU_LEXER_UNMATCHED,
     *                               DOKU_LEXER_ENTER, DOKU_LEXER_MATCHED, DOKU_LEXER_EXIT).
     * @param int           $pos     The byte position of the match within the original text.
     * @param Doku_Handler  $handler The DokuWiki handler object, used to pass data through the parser.
     * @return array|bool   Returns an array containing the captured CSS if the state is DOKU_LEXER_UNMATCHED,
     *                      otherwise returns true to indicate successful handling of enter/exit states.
     */
    public function handle($match, $state, $pos, Doku_Handler $handler){
        // DOKU_LEXER_UNMATCHED state indicates that the content *between* the
        // entry and exit patterns is being processed. This is where the actual
        // CSS code is captured.
        if ($state === DOKU_LEXER_UNMATCHED) {
            // Return an associative array with the key 'css' holding the matched CSS content.
            return ['css' => $match];
        }
        // For DOKU_LEXER_ENTER and DOKU_LEXER_EXIT states, we just return true
        // as no specific data needs to be captured for these states beyond marking
        // the start/end of the container.
        return true;
    }

    /**
     * Renders the handled data into the DokuWiki output.
     *
     * This method is called by the DokuWiki renderer to generate the final HTML.
     * For this plugin, the 'render' method primarily serves for debugging.
     * The actual injection of CSS into the page is typically handled by an
     * action plugin that listens to DokuWiki events (e.g., 'TPL_METAHEADERS').
     *
     * @param string        $mode     The rendering mode (e.g., 'xhtml', 'odt').
     * @param Doku_Renderer $renderer The DokuWiki renderer object.
     * @param mixed         $data     The data returned by the `handle()` method.
     * @return bool     Always returns true, indicating that the rendering process for this element is complete.
     */
    public function render($mode, Doku_Renderer $renderer, $data) {
        // Check if the data received is an array, which means it contains the
        // CSS content captured in the `handle` method (state DOKU_LEXER_UNMATCHED).
        if (is_array($data)) {
            // Log the captured CSS to the DokuWiki debug log.
            // substr($data['css'], 0, 200) truncates the CSS string to the first 200 characters
            // for brevity in the log, preventing very long log entries.
            dbglog("Captured CSS: " . substr($data['css'], 0, 200));

            // It's important to note that this `render` method *does not* output
            // the CSS directly to the HTML. If it did, the CSS would appear
            // in the page body, which is not the desired behavior for CSS.
            // The purpose of this plugin is to *capture* the CSS, and then a
            // separate part of the plugin (likely an `action.php` file
            // listening to `TPL_METAHEADERS` or similar events) would
            // retrieve this captured CSS from a global variable or cache
            // and insert it into the appropriate section of the HTML <head>.
        }
        // Always return true to indicate successful rendering (even if nothing is output).
        return true;
    }
}
