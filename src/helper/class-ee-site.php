<?php

namespace EE\Site\Type;

use EE;
use EE\Model\Site;
use Symfony\Component\Filesystem\Filesystem;
use function EE\Site\Utils\auto_site_name;
use function EE\Site\Utils\get_site_info;
use function EE\Site\Utils\reload_global_nginx_proxy;

/**
 * Base class for Site command
 *
 * @package ee
 */
abstract class EE_Site_Command {
	/**
	 * @var Filesystem $fs Symfony Filesystem object.
	 */
	private $fs;

	/**
	 * @var string $le_mail Mail id to be used for letsencrypt registration and certificate generation.
	 */
	private $le_mail;

	/**
	 * @var array $site_data Associative array containing essential site related information.
	 */
	private $site_data;

	public function __construct() {

		pcntl_signal( SIGTERM, [ $this, 'rollback' ] );
		pcntl_signal( SIGHUP, [ $this, 'rollback' ] );
		pcntl_signal( SIGUSR1, [ $this, 'rollback' ] );
		pcntl_signal( SIGINT, [ $this, 'rollback' ] );
		$shutdown_handler = new Shutdown_Handler();
		register_shutdown_function( [ $shutdown_handler, 'cleanup' ], [ &$this ] );

		$this->fs = new Filesystem();
	}

	/**
	 * Lists the created websites.
	 * abstract list
	 *
	 * [--enabled]
	 * : List only enabled sites.
	 *
	 * [--disabled]
	 * : List only disabled sites.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - yaml
	 *   - json
	 *   - count
	 *   - text
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List all sites
	 *     $ ee site list
	 *
	 *     # List enabled sites
	 *     $ ee site list --enabled
	 *
	 *     # List disabled sites
	 *     $ ee site list --disabled
	 *
	 *     # List all sites in JSON
	 *     $ ee site list --format=json
	 *
	 *     # Count all sites
	 *     $ ee site list --format=count
	 *
	 * @subcommand list
	 */
	public function _list( $args, $assoc_args ) {

		\EE\Utils\delem_log( 'site list start' );
		$format   = \EE\Utils\get_flag_value( $assoc_args, 'format' );
		$enabled  = \EE\Utils\get_flag_value( $assoc_args, 'enabled' );
		$disabled = \EE\Utils\get_flag_value( $assoc_args, 'disabled' );

		$sites = Site::all();

		if ( $enabled && ! $disabled ) {
			$sites = Site::where( 'is_enabled', true );
		} elseif ( $disabled && ! $enabled ) {
			$sites = Site::where( 'is_enabled', false );
		}

		if ( empty( $sites ) ) {
			\EE::error( 'No sites found!' );
		}

		if ( 'text' === $format ) {
			foreach ( $sites as $site ) {
				\EE::log( $site->site_url );
			}
		} else {
			$result = array_map(
				function ( $site ) {
					$site->site   = $site->site_url;
					$site->status = $site->site_enabled ? 'enabled' : 'disabled';

					return $site;
				}, $sites
			);

			$formatter = new \EE\Formatter( $assoc_args, [ 'site', 'status' ] );

			$formatter->display_items( $result );
		}

		\EE\Utils\delem_log( 'site list end' );
	}


	/**
	 * Deletes a website.
	 *
	 * ## OPTIONS
	 *
	 * <site-name>
	 * : Name of website to be deleted.
	 *
	 * [--yes]
	 * : Do not prompt for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     # Delete site
	 *     $ ee site delete example.com
	 *
	 */
	public function delete( $args, $assoc_args ) {

		\EE\Utils\delem_log( 'site delete start' );
		$this->site_data = get_site_info( $args, false );

		$db_data = ( empty( $this->site_data['db_host'] ) || 'db' === $this->site_data['db_host'] ) ? [] : [
			'db_host' => $this->site_data['db_host'],
			'db_user' => $this->site_data['db_user'],
			'db_name' => $this->site_data['db_name'],
		];

		\EE::confirm( sprintf( 'Are you sure you want to delete %s?', $this->site_data['site_url'] ), $assoc_args );
		$this->delete_site( 5, $this->site_data['site_url'], $this->site_data['site_fs_path'], $db_data );
		\EE\Utils\delem_log( 'site delete end' );
	}

	/**
	 * Function to delete the given site.
	 *
	 * @param int $level           Level of deletion.
	 *                             Level - 0: No need of clean-up.
	 *                             Level - 1: Clean-up only the site-root.
	 *                             Level - 2: Try to remove network. The network may or may not have been created.
	 *                             Level - 3: Disconnect & remove network and try to remove containers. The containers
	 *                             may not have been created. Level - 4: Remove containers. Level - 5: Remove db entry.
	 * @param string $site_url     Name of the site to be deleted.
	 * @param string $site_fs_path Webroot of the site.
	 * @param array $db_data       Database host, user and password to cleanup db.
	 *
	 * @throws \EE\ExitException
	 */
	protected function delete_site( $level, $site_url, $site_fs_path, $db_data = [] ) {

		$this->fs = new Filesystem();

		if ( $level >= 3 ) {
			if ( \EE::docker()::docker_compose_down( $site_fs_path ) ) {
				\EE::log( "[$site_url] Docker Containers removed." );
			} else {
				\EE::exec( "docker rm -f $(docker ps -q -f=label=created_by=EasyEngine -f=label=site_name=$site_url)" );
				if ( $level > 3 ) {
					\EE::warning( 'Error in removing docker containers.' );
				}
			}
		}

		if ( ! empty( $db_data['db_host'] ) ) {
			\EE\Site\Utils\cleanup_db( $db_data['db_host'], $db_data['db_name'] );
			\EE\Site\Utils\cleanup_db_user( $db_data['db_host'], $db_data['db_user'] );
		}

		if ( $this->fs->exists( $site_fs_path ) ) {
			try {
				$this->fs->remove( $site_fs_path );
			} catch ( \Exception $e ) {
				\EE::debug( $e );
				\EE::error( 'Could not remove site root. Please check if you have sufficient rights.' );
			}
			\EE::log( "[$site_url] site root removed." );
		}

		$config_file_path = EE_CONF_ROOT . '/nginx/conf.d/' . $site_url . '-redirect.conf';

		if ( $this->fs->exists( $config_file_path ) ) {
			try {
				$this->fs->remove( $config_file_path );
			} catch ( \Exception $e ) {
				\EE::debug( $e );
				\EE::error( 'Could not remove site redirection file. Please check if you have sufficient rights.' );
			}
		}


		if ( $level > 4 ) {
			if ( $this->site_data['site_ssl'] ) {
				\EE::log( 'Removing ssl certs.' );
				$crt_file   = EE_CONF_ROOT . "/nginx/certs/$site_url.crt";
				$key_file   = EE_CONF_ROOT . "/nginx/certs/$site_url.key";
				$conf_certs = EE_CONF_ROOT . "/acme-conf/certs/$site_url";
				$conf_var   = EE_CONF_ROOT . "/acme-conf/var/$site_url";

				$cert_files = [ $conf_certs, $conf_var, $crt_file, $key_file ];
				try {
					$this->fs->remove( $cert_files );
				} catch ( \Exception $e ) {
					\EE::warning( $e );
				}
			}

			if ( Site::find( $site_url )->delete() ) {
				\EE::log( 'Removed database entry.' );
			} else {
				\EE::error( 'Could not remove the database entry' );
			}
		}
		\EE::log( "Site $site_url deleted." );
	}

	/**
	 * Update a site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website.
	 *
	 * [--type=<site-type>]
	 * : Update to valid and supported site-type.
	 *
	 * ## EXAMPLES
	 *
	 *     # Update a html site to WordPress.
	 *     $ ee site update example.com --type=wp
	 */
	public function update( $args, $assoc_args ) {
		\EE\Utils\delem_log( 'site update start' );

		$args            = auto_site_name( $args, 'site', __FUNCTION__ );
		$this->site_data = get_site_info( $args );

		$type = \EE\Utils\get_flag_value( $assoc_args, 'type' );

		if ( $type ) {

			$site_create_params = $this->get_site_create_params( $type );

			EE::runcommand( 'site backup ' . $this->site_data['site_url'] );

			try {
				EE::log( 'Removing old site' );
				EE::exec( 'ee site delete --yes ' . $this->site_data['site_url'] );

				EE::log( 'Creating new site of type ' . $type );
				$create = \EE::exec( sprintf( 'ee site create %s --type=%s %s', $this->site_data['site_url'], $type, $site_create_params ) );

				if ( ! $create ) {
					throw new \Exception( 'Unable to create new site of type ' . $type . '. Please check logs for more info' );
				}
			} catch ( \Exception $e ) {
				$old_site_create_params = $this->get_site_create_params( $this->site_data['site_type'] );
				EE::error( 'Encountered error while updating site: ' . $e->getMessage() . "\n" . 'Restoring old site.', false );
				EE::exec( sprintf( 'ee site create %s --type=%s %s', $this->site_data['site_url'], $this->site_data['site_type'], $old_site_create_params ) );

				$img_versions       = \EE\Utils\get_image_versions();
				$network            = '';
				$backup_db_location = SITE_BACKUP_ROOT . '/' . $this->site_data['site_url'] . '/db';

				if ( GLOBAL_DB === $this->site_data['db_host'] ) {
					$network = "--network='" . GLOBAL_BACKEND_NETWORK . "'";
				} elseif ( 'db' === $this->site_data['db_host'] ) {
					$network = "--network='" . $this->site_data['site_url'] . "'";
				}

				$import_command = sprintf( "docker run -it -v %s:/db_dump --rm %s easyengine/mariadb:%s sh -c \"mysql --host='%s' --port='%s' --user='%s' --password='%s' --database='%s' < /db_dump/%s.sql\"", $backup_db_location, $network, $img_versions['easyengine/mariadb'], $this->site_data['db_host'], $this->site_data['db_port'], $this->site_data['db_user'], $this->site_data['db_password'], $this->site_data['db_name'], $this->site_data['site_url'], $this->site_data['site_url'] );

				EE::log( 'Restoring database' );

				if ( ! EE::exec( $import_command ) ) {
					EE::error( 'Unable to import from mysql dump. Aborting.' );
				}
				EE::log( 'Site restored successfully' );
				return;
			}

			/**
			 * Backup existing configuration before restoring user configuration.
			 * If new config causes error in `nginx -t`, then user's custom config will be removed.
			 */
			$config_backup_path = '/tmp/' . EE\Utils\random_password( 7 ) ;
			$this->fs->mirror($this->site_data['site_url'] . '/config', $config_backup_path );
			$this->fs->mirror( SITE_BACKUP_ROOT . '/' . $this->site_data['site_url'] . '/config', $this->site_data['site_fs_path'] . '/config', null, [ 'override' => true ] );

			chdir( $this->site_data['site_fs_path'] );

			if ( ! EE::exec( 'docker-compose exec nginx nginx -t' ) ) {
				EE::warning( 'Looks like your custom config causes Nginx config error. Currently it will be removed. You can manually add it after correcting it from backup.' );
				$this->fs->mirror( $config_backup_path , $this->site_data['site_url'] . '/config', null, [ 'override' => true ] );
			}

		}

		EE::success( 'Site updated successfully' );
	}

	/**
	 * Returns site create params for a given site type
	 *
	 * @param string $type Type of site to create.
	 *
	 * @return mixed
	 */
	private function get_site_create_params ( string $type ) {
		if ( ! empty( $this->site_data['db_host'] ) ) {
			$db_params = [
				'dbname' => $this->site_data['db_name'],
				'dbuser' => $this->site_data['db_user'],
				'dbpass' => $this->site_data['db_password'],
				'dbhost' => $this->site_data['db_host'],
			];

			if ( 'db' === $this->site_data['db_host'] ) {
				$db_params['local-db'] = true;
			}

			if ( 'php' === $type ) {
				$db_params['with-db'] = true;
			}

			$db_params_keys = array_keys( $db_params );

			return array_reduce( $db_params_keys, function ( $carry, $key ) use ( $db_params ) {
				return $carry . " --$key" . ( true !== $db_params[ $key ] ? '=' . $db_params[ $key ] : '' );
			}, '' );
		}
	}

	/**
	 * Backup a site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website.
	 *
	 * [--location=<location>]
	 * : Location to create backup to.
	 *
	 * [--force]
	 * : Force backup even if nginx config is incorrect
	 */
	public function backup ( $args, $assoc_args ) {
		\EE\Utils\delem_log( 'site update start' );
		$args            = auto_site_name( $args, 'site', __FUNCTION__ );
		$this->site_data = get_site_info( $args );

		chdir( $this->site_data['site_fs_path'] );

		if ( ! EE::exec( 'docker-compose exec nginx nginx -t' ) ) {
			EE::error( 'Looks like there is some error in your nginx config. Please fix it to continue backup.' );
		}

		EE::log( 'Taking backup of ' . $this->site_data['site_url'] );

		$backup_location       = \EE\Utils\get_flag_value( $assoc_args, 'location', SITE_BACKUP_ROOT . '/' . $this->site_data['site_url'] );
		$files_backup_location = $backup_location . '/files/';
		$conf_backup_location  = $backup_location . '/conf/';

		$this->fs->mirror( $this->site_data['site_fs_path'] . '/app/src/', $files_backup_location );
		$this->fs->mirror( $this->site_data['site_fs_path'] . '/config/nginx/custom', $conf_backup_location . '/nginx/custom' );

		if ( 'php' === $this->site_data['site_type'] || 'wp' === $this->site_data['site_type'] ) {
			$this->fs->copy( $this->site_data['site_fs_path'] . '/config/php-fpm/php.ini', $conf_backup_location . '/php-fpm/php.ini' );
		}

		if ( ! empty( $this->site_data['db_host'] ) ) {
			// Backup DB
			EE::log( 'Taking backup of database.' );
			$db_backup_location = $backup_location . '/db/';
			$this->fs->mkdir( $db_backup_location );

			$img_versions = \EE\Utils\get_image_versions();
			$network      = '';

			if ( GLOBAL_DB === $this->site_data['db_host'] ) {
				$network = "--network='" . GLOBAL_BACKEND_NETWORK . "'";
			} elseif ( 'db' === $this->site_data['db_host'] ) {
				$network = "--network='" . $this->site_data['site_url'] . "'";
			}

			$dump_command = sprintf( "docker run -it -v %s:/db_dump --rm %s easyengine/mariadb:%s sh -c \"mysqldump --host='%s' --port='%s' --user='%s' --password='%s' %s > /db_dump/%s.sql\"", $db_backup_location, $network, $img_versions['easyengine/mariadb'], $this->site_data['db_host'], $this->site_data['db_port'], $this->site_data['db_user'], $this->site_data['db_password'], $this->site_data['db_name'], $this->site_data['site_url'] );

			if ( ! EE::exec( $dump_command ) ) {
				EE::error( 'Unable to create mysql dump. Aborting.' );
			}
		}

		EE::success( 'Backup completed successfully. You can find your backup at ' . $backup_location );
	}

	/**
	 * Enables a website. It will start the docker containers of the website if they are stopped.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website to be enabled.
	 *
	 * [--force]
	 * : Force execution of site up.
	 *
	 * ## EXAMPLES
	 *
	 *     # Enable site
	 *     $ ee site enable example.com
	 *
	 */
	public function enable( $args, $assoc_args ) {

		\EE\Utils\delem_log( 'site enable start' );
		$force           = \EE\Utils\get_flag_value( $assoc_args, 'force' );
		$args            = auto_site_name( $args, 'site', __FUNCTION__ );
		$this->site_data = get_site_info( $args, false, true, false );

		if ( $this->site_data->site_enabled && ! $force ) {
			\EE::error( sprintf( '%s is already enabled!', $this->site_data->site_url ) );
		}

		\EE::log( sprintf( 'Enabling site %s.', $this->site_data->site_url ) );

		if ( \EE::docker()::docker_compose_up( $this->site_data->site_fs_path ) ) {
			$this->site_data->site_enabled = 1;
			$this->site_data->save();
			\EE::success( sprintf( 'Site %s enabled.', $this->site_data->site_url ) );
		} else {
			\EE::error( sprintf( 'There was error in enabling %s. Please check logs.', $this->site_data->site_url ) );
		}
		\EE\Utils\delem_log( 'site enable end' );
	}

	/**
	 * Disables a website. It will stop and remove the docker containers of the website if they are running.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website to be disabled.
	 *
	 * ## EXAMPLES
	 *
	 *     # Disable site
	 *     $ ee site disable example.com
	 *
	 */
	public function disable( $args, $assoc_args ) {

		\EE\Utils\delem_log( 'site disable start' );
		$args            = auto_site_name( $args, 'site', __FUNCTION__ );
		$this->site_data = get_site_info( $args, false, true, false );

		\EE::log( sprintf( 'Disabling site %s.', $this->site_data->site_url ) );

		if ( \EE::docker()::docker_compose_down( $this->site_data->site_fs_path ) ) {
			$this->site_data->site_enabled = 0;
			$this->site_data->save();

			\EE::success( sprintf( 'Site %s disabled.', $this->site_data->site_url ) );
		} else {
			\EE::error( sprintf( 'There was error in disabling %s. Please check logs.', $this->site_data->site_url ) );
		}
		\EE\Utils\delem_log( 'site disable end' );
	}

	/**
	 * Restarts containers associated with site.
	 * When no service(--nginx etc.) is specified, all site containers will be restarted.
	 *
	 * [<site-name>]
	 * : Name of the site.
	 *
	 * [--all]
	 * : Restart all containers of site.
	 *
	 * [--nginx]
	 * : Restart nginx container of site.
	 *
	 * ## EXAMPLES
	 *
	 *     # Restart all containers of site
	 *     $ ee site restart example.com
	 *
	 */
	public function restart( $args, $assoc_args, $whitelisted_containers = [] ) {

		\EE\Utils\delem_log( 'site restart start' );
		$args                 = auto_site_name( $args, 'site', __FUNCTION__ );
		$all                  = \EE\Utils\get_flag_value( $assoc_args, 'all' );
		$no_service_specified = count( $assoc_args ) === 0;

		$this->site_data = get_site_info( $args );

		chdir( $this->site_data['site_fs_path'] );

		if ( $all || $no_service_specified ) {
			$containers = $whitelisted_containers;
		} else {
			$containers = array_keys( $assoc_args );
		}

		foreach ( $containers as $container ) {
			\EE\Site\Utils\run_compose_command( 'restart', $container );
		}
		\EE\Utils\delem_log( 'site restart stop' );
	}

	/**
	 * Reload services in containers without restarting container(s) associated with site.
	 * When no service(--nginx etc.) is specified, all services will be reloaded.
	 *
	 * [<site-name>]
	 * : Name of the site.
	 *
	 * [--all]
	 * : Reload all services of site(which are supported).
	 *
	 * [--nginx]
	 * : Reload nginx service in container.
	 *
	 * ## EXAMPLES
	 *
	 *     # Reload all containers of site
	 *     $ ee site reload example.com
	 *
	 */
	public function reload( $args, $assoc_args, $whitelisted_containers = [], $reload_commands = [] ) {

		\EE\Utils\delem_log( 'site reload start' );
		$args = auto_site_name( $args, 'site', __FUNCTION__ );
		$all  = \EE\Utils\get_flag_value( $assoc_args, 'all' );
		if ( ! array_key_exists( 'nginx', $reload_commands ) ) {
			$reload_commands['nginx'] = 'nginx sh -c \'nginx -t && service openresty reload\'';
		}
		$no_service_specified = count( $assoc_args ) === 0;

		$this->site_data = get_site_info( $args );

		chdir( $this->site_data['site_fs_path'] );

		if ( $all || $no_service_specified ) {
			$this->reload_services( $whitelisted_containers, $reload_commands );
		} else {
			$this->reload_services( array_keys( $assoc_args ), $reload_commands );
		}
		\EE\Utils\delem_log( 'site reload stop' );
	}

	/**
	 * Executes reload commands. It needs separate handling as commands to reload each service is different.
	 *
	 * @param array $services        Services to reload.
	 * @param array $reload_commands Commands to reload the services.
	 */
	private function reload_services( $services, $reload_commands ) {

		foreach ( $services as $service ) {
			\EE\Site\Utils\run_compose_command( 'exec', $reload_commands[ $service ], 'reload', $service );
		}
	}

	/**
	 * Runs the acme le registration and authorization.
	 *
	 * @param string $site_url Name of the site for ssl.
	 *
	 * @throws \Exception
	 */
	protected function inherit_certs( $site_url ) {

		$parent_site_name = implode( '.', array_slice( explode( '.', $site_url ), 1 ) );
		$parent_site      = Site::find( $parent_site_name, [ 'site_ssl', 'site_ssl_wildcard' ] );

		if ( ! $parent_site ) {
			throw new \Exception( 'Unable to find existing site: ' . $parent_site_name );
		}

		if ( ! $parent_site->site_ssl ) {
			throw new \Exception( "Cannot inherit from $parent_site_name as site does not have SSL cert" . var_dump( $parent_site ) );
		}

		if ( ! $parent_site->site_ssl_wildcard ) {
			throw new \Exception( "Cannot inherit from $parent_site_name as site does not have wildcard SSL cert" );
		}

		// We don't have to do anything now as nginx-proxy handles everything for us.
		\EE::success( 'Inherited certs from parent' );
	}

	/**
	 * Runs SSL procedure.
	 *
	 * @param string $site_url     Name of the site for ssl.
	 * @param string $site_fs_path Webroot of the site.
	 * @param string $ssl_type     Type of ssl cert to issue.
	 * @param bool $wildcard       SSL with wildcard or not.
	 *
	 * @throws \EE\ExitException If --ssl flag has unrecognized value.
	 * @throws \Exception
	 */
	protected function init_ssl( $site_url, $site_fs_path, $ssl_type, $wildcard = false ) {

		\EE::debug( 'Starting SSL procedure' );
		if ( 'le' === $ssl_type ) {
			\EE::debug( 'Initializing LE' );
			$this->init_le( $site_url, $site_fs_path, $wildcard );
		} elseif ( 'inherit' === $ssl_type ) {
			if ( $wildcard ) {
				throw new \Exception( 'Cannot use --wildcard with --ssl=inherit', false );
			}
			\EE::debug( 'Inheriting certs' );
			$this->inherit_certs( $site_url );
		} else {
			throw new \Exception( "Unrecognized value in --ssl flag: $ssl_type" );
		}
	}

	/**
	 * Runs the acme le registration and authorization.
	 *
	 * @param string $site_url     Name of the site for ssl.
	 * @param string $site_fs_path Webroot of the site.
	 * @param bool $wildcard       SSL with wildcard or not.
	 */
	protected function init_le( $site_url, $site_fs_path, $wildcard = false ) {

		\EE::debug( 'Wildcard in init_le: ' . ( bool ) $wildcard );

		$this->site_data['site_url']          = $site_url;
		$this->site_data['site_fs_path']      = $site_fs_path;
		$this->site_data['site_ssl_wildcard'] = $wildcard;
		$client                               = new Site_Letsencrypt();
		$this->le_mail                        = \EE::get_runner()->config['le-mail'] ?? \EE::input( 'Enter your mail id: ' );
		\EE::get_runner()->ensure_present_in_config( 'le-mail', $this->le_mail );
		if ( ! $client->register( $this->le_mail ) ) {
			$this->site_data['site_ssl'] = null;

			return;
		}

		$domains = $this->get_cert_domains( $site_url, $wildcard );

		if ( ! $client->authorize( $domains, $wildcard ) ) {
			return;
		}
		if ( $wildcard ) {
			echo \cli\Colors::colorize( '%YIMPORTANT:%n Run `ee site ssl ' . $this->site_data['site_url'] . '` once the DNS changes have propagated to complete the certification generation and installation.', null );
		} else {
			$this->ssl( [], [] );
		}
	}

	/**
	 * Returns all domains required by cert
	 *
	 * @param string $site_url  Name of site
	 * @param $wildcard         Wildcard cert required?
	 *
	 * @return array
	 */
	private function get_cert_domains( string $site_url, $wildcard ): array {

		$domains = [ $site_url ];
		if ( $wildcard ) {
			$domains[] = "*.{$site_url}";
		} else {
			$domains[] = $this->get_www_domain( $site_url );
		}

		return $domains;
	}

	/**
	 * If the domain has www in it, returns a domain without www in it.
	 * Else returns a domain with www in it.
	 *
	 * @param string $site_url Name of site
	 *
	 * @return string Domain name with or without www
	 */
	private function get_www_domain( string $site_url ): string {

		$has_www = ( strpos( $site_url, 'www.' ) === 0 );

		if ( $has_www ) {
			return ltrim( $site_url, 'www.' );
		} else {
			return 'www.' . $site_url;
		}
	}


	/**
	 * Verifies ssl challenge and also renews certificates(if expired).
	 *
	 * ## OPTIONS
	 *
	 * <site-name>
	 * : Name of website.
	 *
	 * [--force]
	 * : Force renewal.
	 */
	public function ssl( $args = [], $assoc_args = [] ) {

		EE::log( 'Starting SSL verification.' );

		// This checks if this method was called internally by ee or by user
		$called_by_ee = isset( $this->site_data['site_url'] );

		if ( ! $called_by_ee ) {
			$this->site_data = get_site_info( $args );
		}

		if ( ! isset( $this->le_mail ) ) {
			$this->le_mail = \EE::get_config( 'le-mail' ) ?? \EE::input( 'Enter your mail id: ' );
		}

		$force   = \EE\Utils\get_flag_value( $assoc_args, 'force' );
		$domains = $this->get_cert_domains( $this->site_data['site_url'], $this->site_data['site_ssl_wildcard'] );
		$client  = new Site_Letsencrypt();

		try {
			$client->check( $domains, $this->site_data['site_ssl_wildcard'] );
		} catch ( \Exception $e ) {
			if ( $called_by_ee ) {
				throw $e;
			}
			EE::error( 'Failed to verify SSL: ' . $e->getMessage() );
			return;
		}

		$san = array_values( array_diff( $domains, [ $this->site_data['site_url'] ] ) );
		$client->request( $this->site_data['site_url'], $san, $this->le_mail, $force );

		if ( ! $this->site_data['site_ssl_wildcard'] ) {
			$client->cleanup();
		}

		reload_global_nginx_proxy();

		EE::log( 'SSL verification completed.' );
	}

	/**
	 * Shutdown function to catch and rollback from fatal errors.
	 */
	protected function shut_down_function() {

		$logger = \EE::get_file_logger()->withName( 'site-command' );
		$error  = error_get_last();
		if ( isset( $error ) && $error['type'] === E_ERROR ) {
			\EE::warning( 'An Error occurred. Initiating clean-up.' );
			$logger->error( 'Type: ' . $error['type'] );
			$logger->error( 'Message: ' . $error['message'] );
			$logger->error( 'File: ' . $error['file'] );
			$logger->error( 'Line: ' . $error['line'] );
			$this->rollback();
		}
	}

	abstract public function create( $args, $assoc_args );

	abstract protected function rollback();

}
