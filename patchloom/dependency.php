<?php
defined('ABSPATH') || exit;



function arv_dependency($request) {
    require_once ABSPATH.'wp-admin/includes/plugin.php';
    $reply = arv_ask('If this task needs an additional WordPress plugin, return {"slug":"official-wordpress-org-slug","reason":"why"}. For commercial dependencies use {"vendor":"name","reason":"why"}. Otherwise return {}. Installed slugs are supplied.', [
        'request'=>$request, 'installed'=>array_keys(get_plugins())
    ]);
    if (!empty($reply['slug']) || !empty($reply['vendor'])) {
        $name = sanitize_text_field($reply['vendor'] ?? $reply['slug']);
        if (current_user_can('install_plugins')) {
            $url = empty($reply['vendor']) ? add_query_arg(['s'=>$name,'tab'=>'search'],admin_url('plugin-install.php')) : admin_url('plugin-install.php?tab=upload');
            echo '<p><a href="'.esc_url($url).'">Review plugin or upload licensed ZIP</a></p>';
        }
        throw new Exception($name . ' — ' . sanitize_text_field($reply['reason'] ?? 'Review this dependency, then retry.'));
    }
}

