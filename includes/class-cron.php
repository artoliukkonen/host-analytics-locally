<?php
/* * * * * * * * * * * * * * * * * * * *
 *  ██████╗ █████╗  ██████╗ ███████╗
 * ██╔════╝██╔══██╗██╔═══██╗██╔════╝
 * ██║     ███████║██║   ██║███████╗
 * ██║     ██╔══██║██║   ██║╚════██║
 * ╚██████╗██║  ██║╚██████╔╝███████║
 *  ╚═════╝╚═╝  ╚═╝ ╚═════╝ ╚══════╝
 *
 * @author   : Daan van den Bergh
 * @url      : https://daan.dev/wordpress-plugins/caos/
 * @copyright: (c) 2021 Daan van den Bergh
 * @license  : GPL2v2 or later
 * * * * * * * * * * * * * * * * * * * */

defined('ABSPATH') || exit;

class CAOS_Cron
{
    /**
     * @var $file
     */
    private $file;

    /**
     * Downloads $remoteFile, check if $localFile exists and if so deletes it, then writes it to $localFile
     *
     * @param $localFile
     * @param $remoteFile
     *
     * @return void|string
     */
    protected function download_file($localFile, $remoteFile)
    {
        do_action('caos_admin_update_before');

        $this->file = wp_remote_get($remoteFile, $localFile);

        if (is_wp_error($this->file)) {
            return $this->file->get_error_code() . ': ' . $this->file->get_error_message();
        }

        $pathinfo       = pathinfo($localFile);
        $base           = $pathinfo['basename'];
        $file           = $pathinfo['filename'];
        $dir            = $pathinfo['dirname'];
        $file_aliases   = get_option(CAOS_Admin_Settings::CAOS_CRON_FILE_ALIASES);
        $file_alias     = $file_aliases[$file] ?? '';
        $new_file_alias = bin2hex(random_bytes(4)) . '.js';

        /**
         * Some servers don't do a full overwrite if file already exists, so we delete it first.
         */
        if ($file_alias && file_exists($dir . '/' . $file_alias)) {
            unlink($dir . '/' . $file_alias);
        }

        CAOS::filesystem()->put_contents($dir . '/' . $new_file_alias, $this->file['body']);

        $file_aliases[$file] = $new_file_alias;

        update_option(CAOS_Admin_Settings::CAOS_CRON_FILE_ALIASES, $file_aliases);

        do_action('caos_admin_update_after');
    }

    /**
     * Create directories recursive
     *
     * @param $path
     */
    protected function create_dir_recursive($path)
    {
        if (!file_exists($path)) {
            wp_mkdir_p($path);
        }
    }

    /**
     * Find $find in $file and replace with $replace.
     *
     * @param $file string
     * @param $find array|string
     * @param $replace array|string
     */
    protected function find_replace_in($file, $find, $replace)
    {
        return file_put_contents($file, str_replace($find, $replace, file_get_contents($file)));
    }

    /**
     * Opens file and replaces every instance of google-analytics.com with CAOS' proxy endpoint
     * inside $file.
     * Used only when Stealth Mode is enabled.
     *
     * @param $file
     */
    protected function insert_proxy($file, $add_protocol = false, $replace_url = 'www.google-analytics.com')
    {
        $site_url = get_home_url(CAOS_BLOG_ID);

        if (!$add_protocol) {
            $find             = ['http://', 'https://'];
            $replace          = '';
            $site_url         = str_replace($find, $replace, $site_url);
        }

        $proxy_url        = apply_filters('caos_stealth_mode_proxy_uri', $site_url . CAOS_PROXY_URI);
        $google_endpoints = apply_filters('caos_stealth_mode_google_endpoints', []);
        $caos_endpoint    = apply_filters('caos_stealth_mode_endpoint', '');

        /**
         * Needs to be triggered twice, because $google_endpoints is an array and would otherwise become a multi-dimensional array.
         */
        $new_file         = $this->find_replace_in($file, $google_endpoints, $caos_endpoint);
        $new_file         = $this->find_replace_in($file, $replace_url, $proxy_url);

        return $new_file;
    }
}