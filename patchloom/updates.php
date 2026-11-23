<?php
defined('ABSPATH') || exit;

function patchloom_release($repo) {
    if (!preg_match('~^[A-Za-z0-9-]+/[A-Za-z0-9_-][A-Za-z0-9_.-]*$~D',$repo)) return new WP_Error('repo','Enter your public GitHub repository as owner/repository.');
    $response=wp_safe_remote_get('https://api.github.com/repos/'.$repo.'/releases/latest',['timeout'=>10,'headers'=>['Accept'=>'application/vnd.github+json']]);
    if (is_wp_error($response)) return $response;
    $status=wp_remote_retrieve_response_code($response);
    if ($status!==200) return new WP_Error('release','GitHub HTTP '.$status.'. Check repository visibility and whether a release has been published.');
    $release=json_decode(wp_remote_retrieve_body($response),true);
    $tag=$release['tag_name']??'';
    if (!preg_match('/^v?(\d+\.\d+\.\d+)$/D',$tag,$m)) return new WP_Error('tag','Use a release tag such as v0.5.2.');
    $package='https://github.com/'.$repo.'/releases/download/'.$tag.'/patchloom.zip';
    foreach (($release['assets']??[]) as $asset) {
        if (($asset['name']??'')==='patchloom.zip' && strcasecmp($asset['browser_download_url']??'',$package)===0) return ['version'=>$m[1],'package'=>$asset['browser_download_url'],'url'=>'https://github.com/'.$repo.'/releases/tag/'.$tag,'slug'=>'patchloom'];
    }
    return new WP_Error('asset','Attach patchloom.zip as a release asset.');
}

add_filter('update_plugins_github.com',function($update,$data,$file) {
    if ($file!==plugin_basename(__DIR__.'/patchloom.php')) return $update;
    $repo=get_site_option('patchloom_release_repo','');
    if (!$repo) return false;
    $release=patchloom_release($repo);
    return is_wp_error($release)?false:$release;
},10,3);

function patchloom_updates_screen() {
    if (!current_user_can('update_plugins')) return;
    $repo=get_site_option('patchloom_release_repo','');
    echo '<div class="wrap"><h1>PatchLoom updates</h1>';
    if (isset($_POST['patchloom_check'])) {
        check_admin_referer('patchloom_update_source');
        $candidate=trim(sanitize_text_field(wp_unslash($_POST['repo']??'')));
        if ($candidate==='') {
            delete_site_option('patchloom_release_repo');
            delete_site_transient('update_plugins');
            $repo='';
            echo '<p>Release source disconnected.</p>';
        } else {
            $release=patchloom_release($candidate);
            if (is_wp_error($release)) echo '<p role="alert">'.esc_html($release->get_error_message()).'</p>';
            else {
                $repo=$candidate;
                update_site_option('patchloom_release_repo',$repo);
                delete_site_transient('update_plugins');
                wp_update_plugins();
                echo '<p>Connected. Latest release: '.esc_html($release['version']).'. <a href="'.esc_url(self_admin_url('plugins.php')).'">View installed plugins</a></p>';
            }
        }
    }
    echo '<form method="post">';
    wp_nonce_field('patchloom_update_source');
    echo '<p>Choose a public release repository you control. Its publisher supplies executable plugin updates for this installation.</p><label>GitHub repository <input name="repo" value="'.esc_attr($repo).'" placeholder="owner/repository"></label> <button class="button" name="patchloom_check">Save and check</button></form></div>';
}
