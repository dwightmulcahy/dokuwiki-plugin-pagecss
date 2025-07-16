<?php
/**
 * DokuWiki Plugin Page CSS (syntax_plugin_pagecss.php)
 *
 * Allows custom per-page CSS injection using <css> blocks.
 * Auto-supports Wrap plugin classes, with optional minification and raw div styling control.
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author dWiGhT Mulcahy <support@example.com>
 */

if (!defined('DOKU_INC')) die();
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');

require_once(DOKU_PLUGIN . 'syntax.php');

class syntax_plugin_pagecss extends DokuWiki_Syntax_Plugin {

    // Accumulated CSS for the current page
    private static $css = '';

    /**
     * Constructor: Register CSS injection hook for <head>
     */
    public function __construct() {
        global $EVENT_HANDLER;
        $EVENT_HANDLER->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'inject_css');
    }

    /**
     * Plugin metadata (used by plugin manager and DokuWiki core)
     */
    public function getInfo() {
        return array(
            'author' => 'dWiGhT Mulcahy',
            'email'  => 'support@example.com',
            'date'   => '2025-07-15', // Updated date
            'name'   => 'Page CSS Plugin',
            'desc'   => 'Allows custom per-page CSS injection using <css> blocks. Auto-supports Wrap plugin classes, with optional minification and raw div styling control.',
            'url'    => 'https://www.dokuwiki.org/plugin:pagecss'
        );
    }

    /**
     * Define plugin settings for DokuWiki configuration manager
     */
    public function _get_settings() {
        return array('minify_css', 'disable_raw_div_styling');
    }

    /**
     * Declares the syntax type
     */
    public function getType() {
        return 'container';
    }

    /**
     * Declares the paragraph type (block-level)
     */
    public function getPType() {
        return 'block';
    }

    /**
     * Sort order among other plugins
     */
    public function getSort() {
        return 199;
    }

    /**
     * Declare the custom <css>...</css> tag entry
     */
    public function connectTo($mode) {
        $this->Lexer->addEntryPattern('<css>(?=.*?</css>)', $mode, 'plugin_pagecss');
    }

    /**
     * Declare the closing </css> tag
     */
    public function postConnect() {
        $this->Lexer->addExitPattern('</css>', 'plugin_pagecss');
    }

    /**
     * Handle the CSS content between <css> tags
     */
    public function handle($match, $state, $pos, Doku_Handler $handler) {
        if ($state === DOKU_LEXER_UNMATCHED) {
            $css_block_content = trim($match);
            $processed_css_block = ''; // Will store CSS after filtering (original rules that pass)
            $generated_wrap_css = '';   // Will store only the newly generated wrap_ rules

            $disable_raw_div_styling = $this->getConf('disable_raw_div_styling');

            // Regex to find CSS rules (selectors and their declarations)
            // This regex attempts to capture the full rule and then its selector and declaration parts.
            // It aims to be robust for common CSS but might not cover all edge cases (e.g., deeply nested @media rules, comments within selectors).
            preg_match_all(
                '/((?:[.#]?[a-zA-Z0-9\s,\-_:>\+~*\[\]="\']+)+\s*)({[^}]+})/s',
                $css_block_content,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $rule_match) {
                $selectors_part_with_whitespace = $rule_match[1]; // e.g., "  .my-class  " or "div, p "
                $declarations_part = $rule_match[2]; // e.g., "{color:red;}"
                $full_rule_text = $selectors_part_with_whitespace . $declarations_part; // Reconstruct the full rule for output

                $selectors_part = trim($selectors_part_with_whitespace); // Trimmed selectors for logic

                // Check for raw div styling if option is enabled
                $is_raw_div_rule = false;
                if ($disable_raw_div_styling) {
                    $selectors_array = array_map('trim', explode(',', $selectors_part));
                    foreach ($selectors_array as $selector) {
                        // More robust check for 'div' as a standalone or unqualified selector:
                        // Matches 'div' exactly, or 'div' preceded by start of string, space, or comma,
                        // AND NOT followed by a class (.), ID (#), attribute ([), pseudo-class/element (:), or another word character.
                        if (preg_match('/(^|\s|,)(div)(?!\s*[\.#\[:]|\w)/i', $selector, $div_check_matches)) {
                            // Ensure it's not part of a larger word like 'division' or 'divine'
                            if (isset($div_check_matches[2]) && strtolower($div_check_matches[2]) === 'div') {
                                // Double check if 'div' is really a standalone or unqualified element selector
                                // This is tricky, a simple `div` selector should not contain any class/id/attribute/pseudo
                                if (!preg_match('/[\.#\[:]/', $selector)) { // Check if the selector contains . # [ : after div
                                    // Remove 'div' and check if anything else remains in the selector that would qualify it
                                    $remaining_selector_parts = trim(str_ireplace('div', '', $selector));
                                    if (empty($remaining_selector_parts)) {
                                        $is_raw_div_rule = true;
                                        break; // Found a raw div rule, no need to check other selectors in this rule
                                    }
                                }
                            }
                        }
                    }
                }

                if ($is_raw_div_rule) {
                    // Skip this rule entirely if it's a disabled raw div styling rule
                    continue;
                }

                // --- Begin existing logic for wrap class generation ---
                $current_rule_wrap_selectors = [];
                $should_generate_wrap = false;

                $selectors = array_map('trim', explode(',', $selectors_part));

                foreach ($selectors as $selector) {
                    // Check if the selector contains a class (e.g., .my-class)
                    // The class name must start with a letter or underscore and contain alphanumeric, underscore, or hyphen
                    if (preg_match('/\.([a-zA-Z_][a-zA-Z0-9_-]*)/', $selector, $class_matches)) {
                        $original_class = $class_matches[1];
                        $wrap_class = 'wrap_' . $original_class;

                        // Only add if the wrap version isn't already explicitly in the selector
                        if (strpos($selector, '.' . $wrap_class) === false) {
                            // Replace the original class with its wrap_ version in the selector
                            // Use word boundaries \b to ensure we only replace the full class name
                            $current_rule_wrap_selectors[] = preg_replace('/\b\.' . preg_quote($original_class, '/') . '\b/', '.' . $wrap_class, $selector);
                            $should_generate_wrap = true;
                        } else {
                            // If .wrap_class already exists, just add the original selector
                            $current_rule_wrap_selectors[] = $selector;
                        }
                    } else {
                        // If no class found, just add the original selector (it won't be wrapped)
                        $current_rule_wrap_selectors[] = $selector;
                    }
                }

                // Append the original rule to the processed block (it passed the filter)
                $processed_css_block .= $full_rule_text . "\n";

                // If we generated any distinct wrap selectors for this rule, create a new rule
                if ($should_generate_wrap) {
                    $new_wrap_selectors_string = implode(', ', array_filter($current_rule_wrap_selectors));
                    if (!empty($new_wrap_selectors_string) && $new_wrap_selectors_string !== $selectors_part) {
                        $generated_wrap_css .= $new_wrap_selectors_string . ' ' . $declarations_part . "\n";
                    }
                }
            }

            self::$css .= $processed_css_block; // Add original CSS after filtering
            self::$css .= $generated_wrap_css; // Add only the newly generated wrap CSS
        }
        return false;
    }

    /**
     * This plugin doesn't render visible output
     */
    public function render($mode, Doku_Renderer $renderer, $data) {
        return true;
    }

    /**
     * Injects the collected CSS into the <head> of the page
     */
    public function inject_css(Doku_Event $event, $param) {
        if (empty(self::$css)) return;

        $final_css = self::$css;

        // Check if minification is enabled
        if ($this->getConf('minify_css')) {
            $final_css = $this->minifyCss($final_css);
        }

        $event->data['style'][] = array(
            'type'  => 'text/css',
            'media' => 'screen',
            '_data' => $final_css
        );
    }

    /**
     * Minifies CSS content.
     * A simple regex-based minifier.
     *
     * @param string $css The CSS string to minify.
     * @return string The minified CSS string.
     */
    private function minifyCss($css) {
        // Remove comments
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        // Remove newlines, tabs, and multiple spaces
        $css = str_replace(array("\r\n", "\r", "\n", "\t", '  ', '    ', '    '), '', $css);
        // Remove space after colon for properties but not for selectors like :hover
        $css = preg_replace('/:\s*([^{;]+);/', ':$1;', $css); // space before value, before ;
        $css = preg_replace('/:\s*([^{;]+)}/', ':$1}', $css); // space before value, before }
        // Remove space before and after curly braces
        $css = str_replace(array(' {', '{ ', ' }', '} '), array('{', '{', '}', '}'), $css);
        // Remove space before and after comma
        $css = str_replace(', ', ',', $css);
        $css = str_replace(' ;', ';', $css); // Remove space before semicolon
        // Remove the last semicolon of the last rule in a block
        $css = preg_replace('/;}/', '}', $css);
        return trim($css);
    }

    /**
     * Explicitly define allowed container types (optional)
     */
    public function getAllowedTypes() {
        return array('container');
    }
}
