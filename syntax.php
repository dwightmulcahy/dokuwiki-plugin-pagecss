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
            'desc'   => 'Allows custom per-page CSS injection using <css> blocks. Auto-supports Wrap plugin classes.',
            'url'    => 'https://www.dokuwiki.org/plugin:pagecss'
        );
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
            $css = trim($match);

            // Auto-add .wrap_ versions for WRAP plugin compatibility
            preg_match_all('/\\.(\\w[\\w\\-]*)/', $css, $matches);
            foreach ($matches[1] as $class) {
                $wrapClass = 'wrap_' . $class;
                if (strpos($css, '.' . $wrapClass) === false) {
                    // Duplicate each rule with .wrap_ prefix
                    $pattern = '/\\.' . preg_quote($class, '/') . '(?=\\s*[{,])/';
                    $css .= "\n" . preg_replace($pattern, '.' . $wrapClass, $css);
                }
            }

            self::$css .= $css . "\n";
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
        $event->data['style'][] = array(
            'type' => 'text/css',
            'media' => 'screen',
            '_data' => self::$css
        );
    }

    /**
     * Explicitly define allowed container types (optional)
     */
    public function getAllowedTypes() {
        return array('container');
    }
}
