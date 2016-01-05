<?php
/*
Plugin Name: BLMD Git Updater
Plugin URI: https://github.com/blmd/blmd-git-updater
Description: Allow git updates
Author: blmd
Author URI: https://github.com/blmd
Version: 0.3
*/

!defined( 'ABSPATH' ) && die;
define( 'BLMD_GIT_UPDATER_VERSION', '0.3' );
define( 'BLMD_GIT_UPDATER_URL', plugin_dir_url( __FILE__ ) );
define( 'BLMD_GIT_UPDATER_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLMD_GIT_UPDATER_BASENAME', plugin_basename( __FILE__ ) );

if ( class_exists( 'WP_CLI_Command' ) ):
	class BLMD_Git_Command extends WP_CLI_Command {
	/**
	 * Updates a git repository.
	 *
	 * ## EXAMPLES
	 *
	 *     wp blmd-git pull
	 *
	 */
	public function pull( $args, $assoc_args ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$cur_dir = rtrim( getcwd(), ' /' );
		if ( !is_dir( "$cur_dir/.git" ) ) {
			WP_CLI::error( "'$cur_dir' is not a git repository." );
		}
		$all_plugins = get_plugins();
		foreach ( array_keys( $all_plugins ) as $plugin_file ) {
			$full_path = rtrim( plugin_dir_path( WP_PLUGIN_DIR.'/'.$plugin_file ), ' /' );
			WP_CLI::log( "$full_path <> $cur_dir" );
			if ( $full_path == $cur_dir ) {
				$blmd_git_updater = BLMD_Git_Updater();
				$_REQUEST['plugin_file'] = addslashes( $plugin_file );
				$blmd_git_updater->git_updater();
				break;
			}
		}
	}

};
endif;

class BLMD_Git_Updater {

	public static function factory() {
		static $instance = null;
		if ( ! ( $instance instanceof self ) ) {
			$instance = new self;
			$instance->setup_actions();
			$instance->setup_filters();
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				$instance->setup_cli();
			}
		}
		return $instance;
	}

	protected function setup_actions() {
		// add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_menu', array( $this, 'add_git' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
	}

	protected function setup_filters() {
		add_filter( 'site_transient_update_plugins', array( $this, 'site_transient_update_plugins' ) );
	}
	
	protected function setup_cli() {
		WP_CLI::add_command( 'blmd-git', 'BLMD_Git_Command' );
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
	
	public function add_git() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		foreach ( array_keys( $plugins ) as $plugin_file ) {
			if ( strpos( $plugin_file, '/' ) === false ) { continue; } // single file
			$plugin_dirname = dirname( WP_PLUGIN_DIR.'/'.$plugin_file );
			if ( is_dir( "{$plugin_dirname}/.git" ) ) {
				add_filter( "plugin_action_links_{$plugin_file}", function($links) use($plugin_file){
					return array_merge(
					$links,
						array(
							'settings' => '<a href="' . get_bloginfo( 'wpurl' ) . '/wp-admin/tools.php?page=blmd-git-updater&plugin_file='.urlencode($plugin_file).'">Git</a>',
						)
					);
				});
			}
		}		
	}
	
	public function site_transient_update_plugins( $var ) {
		if ( ( !is_object( $var ) ) || empty( $var->response ) ) { return $var; }
		$screen = get_current_screen();
		if ( !$screen || $screen->id != 'update-core' ) { return $var; }

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
				$screen = get_current_screen();
				$update_info = false;
				// if ($screen->id != 'update-core') {
				// 	$update_info = get_transient( md5( $plugin_file."_update_info" ) );
				// }
				if ( $update_info === false ) {
					chdir( $plugin_dirname );
					`GIT_SSH_COMMAND='ssh -i /srv/www/.ssh/id_rsa_git' git fetch 2>&1`;
					$r = trim( `GIT_SSH_COMMAND='ssh -i /srv/www/.ssh/id_rsa_git' git cherry master origin/master|head -n1 2>&1` );
					if ( !$r ) { $update_info = ''; }
					else {
						$update_info = (object)array(
							'slug' => dirname( $plugin_file ),
							'new_version' => substr( preg_replace( '/^([^a-z0-9]+)/', '', $r ), 0, 8 ),
						);
					}
					// set_transient( md5( $plugin_file."_update_info" ), $update_info, 3600*12 );
				}

				if ( !empty( $update_info ) ) { $var->response[$plugin_file] = $update_info; }
				
			}
		}
		return $var;
	}
	
	public function git_installer() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( "Unauthorized." );}
		if ( empty( $_REQUEST['plugin_git_url'] ) ) { wp_die( "No plugin git url specified." ); }
		$plugin_git_url = stripslashes( $_REQUEST['plugin_git_url'] );
		
		// if (strpos($plugin_git_url, 'git@bitbucket.org:blmd/'))

		chdir( WP_PLUGIN_DIR );
		$r = `pwd; ls -alkh $dir`."\n";
		$r .= `stat -c '%U' .git`."\n";
		// $r .= getenv('SSH_AUTH_SOCK')."\n";
	
		$r .= `GIT_SSH_COMMAND='ssh -i /srv/www/.ssh/id_rsa_git' git ls-remote --get-url`."\n";
		wp_die("Installed");
	}
	
	public function git_updater() {
		if ( !empty( $_REQUEST['plugin_git_url'] ) ) { return $this->git_installer(); }
		if ( empty( $_REQUEST['plugin_file'] ) ) { wp_die( "No plugin specified." ); }
		$plugin_file = stripslashes($_REQUEST['plugin_file']);

		if ( substr_count( $_REQUEST['plugin_file'], '/' ) != 1 ) { wp_die( "Bad plugin file." ); }
		list($plugin_dir, $_) = explode('/', $plugin_file, 2);
		$dir = WP_PLUGIN_DIR.'/'.preg_replace( '/\.+/', '', $plugin_dir );
		if ( !is_dir( $dir ) ) { wp_die( "Bad plugin dir." ); }
		if ( !is_dir( "{$dir}/.git" ) ) { wp_die( "Not under git control." ); }
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
