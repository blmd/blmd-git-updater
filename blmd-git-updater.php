<?php
/*
Plugin Name: BLMD Git Updater
Plugin URI: https://github.com/blmd/blmd-git-updater
Description: Allow git updates
Author: blmd
Author URI: https://github.com/blmd
Version: 0.1
*/

!defined( 'ABSPATH' ) && die;
define( 'BLMD_GIT_UPDATER_VERSION', '0.1' );
define( 'BLMD_GIT_UPDATER_URL', plugin_dir_url( __FILE__ ) );
define( 'BLMD_GIT_UPDATER_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLMD_GIT_UPDATER_BASENAME', plugin_basename( __FILE__ ) );

class BLMD_Git_Updater {

	public static function factory() {
		static $instance = null;
		if ( ! ( $instance instanceof self ) ) {
			$instance = new self;
			$instance->setup_actions();
			$instance->setup_filters();
		}
		return $instance;
	}

	protected function setup_actions() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
	}

	protected function setup_filters() {
		add_filter( 'site_transient_update_plugins', array( $this, 'site_transient_update_plugins' ) );
	}
	
	public function admin_menu() {
		add_management_page(
			__( 'BLMD Git Updater', 'wp' ),
			__( 'BLMD Git Updater', 'wp' ),
			'manage_options',
			'blmd-git-updater',
			array( $this, 'git_updater' )
		);
	}
	
	public function site_transient_update_plugins( $var ) {
		if ( ( !is_object( $var ) ) || empty( $var->response ) ) { return $var; }
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		foreach ( array_keys( $plugins ) as $plugin_file ) {
			if ( strpos( $plugin_file, '/' ) === false ) { continue; } // single file

			if ( ! isset( $var->response ) || ! is_array( $var->response ) ) {
				$var->response = array();
			}

			$plugin_dirname = dirname( WP_PLUGIN_DIR.'/'.$plugin_file );
			if ( is_dir( "{$plugin_dirname}/.git" ) ) {
				add_filter( "plugin_action_links_{$plugin_file}", function($links) use($plugin_file){
					return array_merge(
					$links,
						array(
							'settings' => '<a href="' . get_bloginfo( 'wpurl' ) . '/wp-admin/tools.php?page=git-plugin-update&plugin_file='.urlencode($plugin_file).'">Git</a>',
						)
					);
					
				} );
				$screen = get_current_screen();
				$update_info = false;
				if ($screen->id != 'update-core') {
					$update_info = get_transient( md5( $plugin_file."_update_info" ) );
				}
				if ( $update_info === false ) {
					chdir( $plugin_dirname );
					`GIT_SSH_COMMAND='ssh -i /srv/www/.ssh/id_rsa_git' git fetch 2>&1`;
					$r = trim( `GIT_SSH_COMMAND='ssh -i /srv/www/.ssh/id_rsa_git' git cherry master origin/master|head -n1 2>&1` );
					if ( !$r ) { $update_info = ''; }
					else {
						$update_info = (object)array(
							'slug' => dirname( $plugin_file ),
							'new_version' => substr( preg_replace( '/^([^a-z0-9]+)/', '', $r ), 0, 8 ),
							// 'url' => 'https://github.com/blmd/'.md5($plugin_file),
							// 'package' => 'https://github.com/blmd/'.md5($plugin_file).'.zip',
						);
					}
					set_transient( md5( $plugin_file."_update_info" ), $update_info, 3600*12 );
				}


				if ( !empty( $update_info ) ) { $var->response[$plugin_file] = $update_info; }


			}
		}
		return $var;	
	}
	
	public function git_updater() {
		$plugin_file = stripslashes($_REQUEST['plugin_file']);
		$v = dirname($plugin_file);
		$dir = WP_PLUGIN_DIR.'/'.escapeshellcmd($v);
		chdir($dir);
	
		$r = `pwd; ls -alkh $dir`."\n";
		$r .= `stat -c '%U' .git`."\n";
		// $r .= getenv('SSH_AUTH_SOCK')."\n";
	
		$r .= `GIT_SSH_COMMAND='ssh -i /srv/www/.ssh/id_rsa_git' git ls-remote --get-url`."\n";
		$r .= `GIT_SSH_COMMAND='ssh -i /srv/www/.ssh/id_rsa_git' git fetch 2>&1`."\n";
		// $r .= `GIT_SSH_COMMAND='ssh -i /srv/www/.ssh/id_rsa_git' git cherry origin/master master 2>&1`."\n";
		// $r .= `GIT_SSH_COMMAND='ssh -i /srv/www/.ssh/id_rsa_git' git status origin 2>&1`."\n";
		$r .= `GIT_SSH_COMMAND='ssh -i /srv/www/.ssh/id_rsa_git' git cherry master origin/master 2>&1`."\n";
		$x = trim(`GIT_SSH_COMMAND='ssh -i /srv/www/.ssh/id_rsa_git' git cherry master origin/master 2>&1`);

			$r .= `GIT_SSH_COMMAND='ssh -i /srv/www/.ssh/id_rsa_git' git ls-remote --get-url`."\n";
		$r .= `GIT_SSH_COMMAND='ssh -i /srv/www/.ssh/id_rsa_git' git pull 2>&1`."\n";
		// set_transient( md5( $plugin_file."_update_info" ), '', 3600*12 );
		delete_transient( md5( $plugin_file."_update_info" ) );
		echo "<pre>$r</pre>";
		wp_die("Yup: $v");
	}

	public function __construct() { }

	public function __clone() {
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'core-plugin' ), '0.1' );
	}

	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'core-plugin' ), '0.1' );
	}

};

function BLMD_Git_Updater() {
	return BLMD_Git_Updater::factory();
}

BLMD_Git_Updater();
