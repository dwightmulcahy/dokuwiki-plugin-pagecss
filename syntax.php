<?php
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
            'date'   => '2025-07-15',
            'name'   => 'Page CSS Plugin',
            'desc'   => 'Allows custom per-page CSS injection using <css> blocks. Auto-supports Wrap plugin classes, with optional minification.',
            'url'    => 'https://www.dokuwiki.org/plugin:pagecss'
        );
    }

    /**
     * Define plugin settings for DokuWiki configuration manager
     */
    public function _get_settings() {
        return array('minify_css');
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
            $generated_wrap_css = '';

            // Regex to find CSS rules (selectors and their declarations)
            // This is a simplified regex and might not cover all edge cases
            preg_match_all(
                '/((?:[.#]?[a-zA-Z0-9\s,\-_:>\+~*]+)\s*({[^}]+}))/s', // Improved selector regex
                $css_block_content,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $rule_match) {
                $full_rule = $rule_match[1]; // e.g., ".my-class {color:red;}"
                $selectors_string = trim($rule_match[2]); // e.g., ".my-class"
                $declarations = $rule_match[3]; // e.g., "{color:red;}"

                // Split selectors by comma to handle multiple selectors per rule
                $selectors = array_map('trim', explode(',', $selectors_string));
                $wrap_selectors = [];
                $should_generate_wrap = false;

                foreach ($selectors as $selector) {
                    // Check if the selector contains a class (e.g., .my-class)
                    if (preg_match('/\.([a-zA-Z_][a-zA-Z0-9_-]*)/', $selector, $class_matches)) {
                        $original_class = $class_matches[1];
                        $wrap_class = 'wrap_' . $original_class;

                        // Only add if the wrap version isn't already explicitly in the selector
                        if (strpos($selector, '.' . $wrap_class) === false) {
                            // Replace the original class with its wrap_ version in the selector
                            $wrap_selectors[] = preg_replace('/\.'. preg_quote($original_class, '/') .'/', '.' . $wrap_class, $selector);
                            $should_generate_wrap = true;
                        } else {
                            // If .wrap_class already exists, just add the original selector
                            $wrap_selectors[] = $selector;
                        }
                    } else {
                        // If no class found, just add the original selector (it won't be wrapped)
                        $wrap_selectors[] = $selector;
                    }
                }

                // If we generated any distinct wrap selectors for this rule, create a new rule
                if ($should_generate_wrap) {
                    $new_wrap_selectors_string = implode(', ', array_filter($wrap_selectors));
                    // Ensure we don't accidentally duplicate if all original selectors already had wrap_
                    if (!empty($new_wrap_selectors_string) && $new_wrap_selectors_string !== $selectors_string) {
                         // Find the declarations part from the original full rule
                        preg_match('/({[^}]+})/', $full_rule, $decs_match);
                        if (!empty($decs_match)) {
                            $generated_wrap_css .= $new_wrap_selectors_string . ' ' . $decs_match[1] . "\n";
                        }
                    }
                }
            }

            self::$css .= $css_block_content . "\n"; // Add original CSS
            self::$css .= $generated_wrap_css . "\n"; // Add only the newly generated wrap CSS
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
