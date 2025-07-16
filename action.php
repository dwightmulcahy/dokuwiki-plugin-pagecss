<?php
use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

class action_plugin_pagecss extends ActionPlugin {

    public function register(EventHandler $controller) {
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'inject_css');
        $controller->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, 'handle_metadata');
    }

    public function handle_metadata(\Doku_Event $event) {
        global $ID;
        $text = rawWiki($ID);  // Use raw page content instead of description

        preg_match_all('/<pagecss>(.*?)<\/pagecss>/s', $text, $matches);
        if (!empty($matches[1])) {
            $styles = implode(" ", array_map('trim', $matches[1]));
            if ($styles) {
                preg_match_all('/\.([a-zA-Z0-9_-]+)\s*\{[^}]*\}/', $styles, $class_matches);
                $extra = '';
                foreach ($class_matches[1] as $classname) {
                    $pattern = '/\.' . preg_quote($classname, '/') . '\s*\{([^}]*)\}/';
                    if (preg_match($pattern, $styles, $style_block)) {
                        $extra .= ".wrap_$classname {{$style_block[1]}}\n";
                    }
                }
                $styles .= "\n" . trim($extra);
                p_set_metadata($ID, ['pagecss' => ['styles' => $styles]]);
                return;
            }
        }

        p_set_metadata($ID, ['pagecss' => ['styles' => '']]);
    }

    public function inject_css(Doku_Event $event) {
        global $ID;
        $data = p_get_metadata($ID, 'pagecss');
        $styles = $data['styles'] ?? '';

        if ($styles && is_string($styles)) {
            $event->data['style'][] = [
                'type' => 'text/css',
                'media' => 'screen',
                '_data' => $styles,
            ];
        }
    }
}
