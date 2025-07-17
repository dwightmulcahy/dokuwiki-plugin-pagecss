<?php
/**
 * DokuWiki Plugin Page CSS (syntax_plugin_pagecss.php)
 *
 * Allows custom per-page CSS injection using <css> blocks and per-namespace
 * CSS injection using <nscss> blocks.
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author dWiGhT Mulcahy <support@example.com>
 */

if (!defined('DOKU_INC')) die();
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');

require_once(DOKU_PLUGIN . 'syntax.php');

class syntax_plugin_pagecss extends DokuWiki_Syntax_Plugin {

    // Accumulated page-specific CSS for the current page
    private static $page_css = '';

    // Accumulated namespace-specific CSS, keyed by namespace ID
    // Stored as an array of raw CSS strings from <nscss> blocks, to be processed on save.
    private static $namespace_css_raw = [];

    // Loaded and processed namespace CSS from cache, keyed by namespace ID
    private static $namespace_css_processed = [];


    /**
     * Constructor: Register all necessary DokuWiki hooks
     */
    public function __construct() {
        global $EVENT_HANDLER;
        // Hook for injecting final CSS into the HTML <head>
        $EVENT_HANDLER->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'inject_css');
        // Hook for loading cached namespace CSS when a page is parsed
        $EVENT_HANDLER->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, 'load_namespace_css_from_cache');
        // Hook for saving accumulated namespace CSS when a page is written
        $EVENT_HANDLER->register_hook('IO_WIKIPAGE_WRITE', 'BEFORE', $this, 'save_namespace_css_to_cache');
    }

    /**
     * Plugin metadata (used by plugin manager and DokuWiki core)
     */
    public function getInfo() {
        return array(
            'author' => 'dWiGhT Mulcahy',
            'email'  => 'support@example.com',
            'date'   => '2025-07-16', // Updated date to reflect latest changes
            'name'   => 'Page CSS Plugin',
            'desc'   => 'Allows custom per-page CSS using &lt;css&gt; and per-namespace CSS using &lt;nscss&gt;.',
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
     * Declare the custom <css>...</css> and <nscss>...</nscss> tag entries
     */
    public function connectTo($mode) {
        $this->Lexer->addEntryPattern('<css>(?=.*?</css>)', $mode, 'plugin_pagecss');
        $this->Lexer->addEntryPattern('<nscss>(?=.*?</nscss>)', $mode, 'plugin_pagecss');
    }

    /**
     * Declare the closing tags
     */
    public function postConnect() {
        $this->Lexer->addExitPattern('</css>', 'plugin_pagecss');
        $this->Lexer->addExitPattern('</nscss>', 'plugin_pagecss');
    }

    /**
     * Handle the CSS content between <css> or <nscss> tags
     * This method is responsible for parsing the content of the custom syntax blocks.
     */
    public function handle($match, $state, $pos, Doku_Handler $handler) {
        switch ($state) {
            case DOKU_LEXER_ENTER:
                // Store the type of block being entered
                if ($match === '<css>') return ['type' => 'css_block'];
                if ($match === '<nscss>') return ['type' => 'nscss_block'];
                break;
            case DOKU_LEXER_UNMATCHED:
                // Accumulate the content of the block
                $handler->addData($match);
                break;
            case DOKU_LEXER_EXIT:
                // When exiting a block, retrieve the collected content
                $data = $handler->popData();
                $content = trim(implode('', $data['content']));

                if ($data['type'] === 'css_block') {
                    // For page-specific CSS, append directly
                    self::$page_css .= $content . "\n";
                } elseif ($data['type'] === 'nscss_block') {
                    // For namespace-specific CSS, store the raw content for later processing on save
                    global $ID;
                    $current_ns = getNS($ID);
                    // DokuWiki represents the root namespace as an empty string; canonicalize to ':' for consistency in storage
                    $ns_key = empty($current_ns) ? ':' : $current_ns;
                    
                    if (!isset(self::$namespace_css_raw[$ns_key])) {
                        self::$namespace_css_raw[$ns_key] = '';
                    }
                    self::$namespace_css_raw[$ns_key] .= $content . "\n";
                }
                break;
        }
        return false;
    }

    /**
     * Hook to PARSER_CACHE_USE to load cached namespace CSS.
     * This runs early in the page rendering process to ensure namespace CSS is available.
     *
     * @param Doku_Event $event
     * @param mixed      $param
     */
    public function load_namespace_css_from_cache(Doku_Event $event, $param) {
        // Only load for XHTML rendering mode
        if ($event->data->mode !== 'xhtml') return;

        global $ID;
        $current_page_ns = getNS($ID);
        
        // Build an array of all relevant namespaces, from root up to current.
        $namespaces_to_check = [];
        $parts = explode(':', $current_page_ns);
        $temp_ns = '';
        foreach ($parts as $part) {
            if (!empty($temp_ns)) $temp_ns .= ':';
            $temp_ns .= $part;
            $namespaces_to_check[] = $temp_ns;
        }
        // Always include the root namespace (represented by an empty string or ':')
        if (empty($namespaces_to_check) || $namespaces_to_check[0] !== '') {
            array_unshift($namespaces_to_check, ''); // Add root namespace as empty string
        }
        
        // Load CSS for each relevant namespace from its cache file
        foreach ($namespaces_to_check as $ns_id) {
            // Canonicalize root namespace to ':' for storage/lookup consistency
            $ns_key = empty($ns_id) ? ':' : $ns_id;

            $cache_file = $this->getNamespaceCacheFilePath($ns_key);

            if (file_exists($cache_file)) {
                $content = @file_get_contents($cache_file);
                if ($content !== false) {
                    self::$namespace_css_processed[$ns_key] = $content;
                }
            }
        }
    }

    /**
     * Hook to IO_WIKIPAGE_WRITE to save accumulated namespace CSS to a cache file.
     * This ensures the CSS is processed and saved only when an <nscss> block is edited/saved.
     *
     * @param Doku_Event $event
     * @param mixed      $param
     */
    public function save_namespace_css_to_cache(Doku_Event $event, $param) {
        list($id, $text, $oldtext, $rev) = $event->data;

        global $ID; // $ID refers to the page being saved
        $current_ns_of_saved_page = getNS($ID);
        // Canonicalize root namespace to ':' for storage/lookup consistency
        $ns_key = empty($current_ns_of_saved_page) ? ':' : $current_ns_of_saved_page;

        // Check if any raw namespace CSS was collected during the parsing of the page being saved.
        // This means an <nscss> block was present and its content was accumulated.
        if (isset(self::$namespace_css_raw[$ns_key]) && !empty(self::$namespace_css_raw[$ns_key])) {
            $cache_dir = DOKU_INC . 'data/cache/plugin_pagecss/';
            if (!is_dir($cache_dir)) {
                mkdir($cache_dir, 0755, true); // Create directory if it doesn't exist
            }
            $cache_file = $this->getNamespaceCacheFilePath($ns_key);

            // Save the raw content directly to the cache file (no processing like minification here)
            io_saveFile($cache_file, self::$namespace_css_raw[$ns_key]);
        }
    }

    /**
     * Generates the full path for a namespace's CSS cache file.
     *
     * @param string $ns_id The namespace ID (e.g., 'wiki:syntax', or ':' for root).
     * @return string The full path to the cache file.
     */
    private function getNamespaceCacheFilePath($ns_id) {
        $clean_ns_id = str_replace([':', '/'], '_', $ns_id); // Sanitize ID for filename
        if (empty($clean_ns_id)) $clean_ns_id = '_root_'; // Specific name for root namespace
        return DOKU_INC . 'data/cache/plugin_pagecss/nscss_' . md5($clean_ns_id) . '.css';
    }


    /**
     * This plugin doesn't render visible output in the DokuWiki content area.
     */
    public function render($mode, Doku_Renderer $renderer, $data) {
        return true;
    }

    /**
     * Injects the collected CSS (namespace-specific and page-specific) into the <head> of the page.
     *
     * @param Doku_Event $event
     * @param mixed      $param
     */
    public function inject_css(Doku_Event $event, $param) {
        global $ID;
        $current_page_ns = getNS($ID);
        $namespaces_to_combine = [];

        // Build a list of ancestor namespaces (from root up to current page's namespace)
        $parts = explode(':', $current_page_ns);
        $temp_ns = '';
        foreach ($parts as $part) {
            if (!empty($temp_ns)) $temp_ns .= ':';
            $temp_ns .= $part;
            $namespaces_to_combine[] = $temp_ns;
        }
        // Always ensure the root namespace is included if applicable
        if (empty($namespaces_to_combine) || $namespaces_to_combine[0] !== '') {
            array_unshift($namespaces_to_combine, ''); // Represents the root namespace
        }
        
        $combined_nscss_output = '';
        // Iterate through namespaces in order of inheritance (root first, then deeper)
        foreach ($namespaces_to_combine as $ns_id) {
            $ns_key = empty($ns_id) ? ':' : $ns_id; // Use canonical key for lookup

            // Add the processed CSS loaded from cache for this namespace
            if (isset(self::$namespace_css_processed[$ns_key])) {
                $combined_nscss_output .= self::$namespace_css_processed[$ns_key] . "\n";
            }
        }

        // Combine namespace CSS (already processed) with page-specific CSS (already processed)
        $final_css_output = $combined_nscss_output . self::$page_css;

        if (empty($final_css_output)) return;

        // Add the final CSS to the DokuWiki metaheader output
        $event->data['style'][] = array(
            'type'  => 'text/css',
            'media' => 'screen',
            '_data' => $final_css_output
        );
    }

    /**
     * Explicitly define allowed container types (optional)
     */
    public function getAllowedTypes() {
        return array('container');
    }
}
