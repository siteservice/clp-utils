<?php

namespace SiteService;

use WP_CLI;

class Clp
{

	/**
	 * Copies a WordPress site between a source and a destination environment.
	 *
	 * ## OPTIONS
	 *
	 * [--src=<user>]
	 * : User that owns the source environment.
	 *
	 * [--dest=<user>]
	 * : User that owns the destination environment.
	 *
	 * [--files=<permissions>]
	 * : File permissions to set on the destination environment.
	 *
	 * [--folders=<permissions>]
	 * : Folder permissions to set on the destination environment.
	 *
	 * [--plugins=<boolean>]
	 * : Whether to copy plugins from the source environment to the destination environment.
	 *
	 * [--themes=<boolean>]
	 * : Whether to copy themes from the source environment to the destination environment.
	 *
	 * [--uploads=<boolean>]
	 * : Whether to copy uploads from the source environment to the destination environment.
	 *
	 * ## EXAMPLES
	 * wp clp copy --src=production_user --dest=staging_user --files=660 --folders=770
	 *
	 *  @alias clp-utils
	 */
	public function copy($args, $assoc_args)
	{

		// Multi-line welcome message for creating a staging site
		$welcome_message = WP_CLI::colorize(
			"\n%G*********************************************************%n\n" .
				"%Y*  Staging Tool for CloudPanel WordPress Installations  *%n\n" .
				"%G*********************************************************%n\n" .
				"\n" .
				"%CThis command will help you copy your WordPress website from one environment to another.%n\n" .
				"%CIt will create a safe space to test updates, new plugins, and more.%n\n" .
				"\n" .
				"%RWarning:%n Always ensure you have recent backups before proceeding.\n" .
				"\n" .
				"%GLet's get started!%n\n"
		);

		// Display the welcome message
		WP_CLI::log($welcome_message);

		// WP_CLI::log(print_r($assoc_args, true));

		$src = isset($assoc_args['src']) ? $assoc_args['src'] : '';
		$dest = isset($assoc_args['dest']) ? $assoc_args['dest'] : '';

		$files = isset($assoc_args['files']) ? $assoc_args['files'] : '660';
		$folders = isset($assoc_args['folders']) ? $assoc_args['folders'] : '770';


		if (empty($src) || empty($dest)) {
			WP_CLI::error("Source and destination environments are required.");
		}

		if (! file_exists("/home/$src")) {
			WP_CLI::error("Source environment does not exist.");
		}

		if (! file_exists("/home/$dest")) {
			WP_CLI::error("Destination environment does not exist.");
		}

		// Get configuration settings for both environments
		$src_config = $this->find_wordpress($src);
		$dest_config = $this->find_wordpress($dest);

		// Prepare data for the table
		$data = [
			[
				'key' => 'PATH',
				'source' => $src_config['path_folder'],
				'destination' => $dest_config['path_folder']
			],
			[
				'key' => 'URL',
				'source' => $src_config['url'],
				'destination' => $dest_config['url']
			],
			[
				'key' => 'DATABASE',
				'source' => $src_config['db_name'],
				'destination' => $dest_config['db_name']
			],
		];

		// Define the table headers
		$fields = ['key', 'source', 'destination'];

		// Output the table
		WP_CLI\Utils\format_items('table', $data, $fields);


		WP_CLI::confirm("\nDoes this information look correct?", $assoc_args);


		// STAGE 1: Synchronize Files
		try {
			$this->synchronize_files($src_config, $dest_config, $assoc_args);
			WP_CLI::success("File sync completed!");
		} catch (\Exception $e) {
			WP_CLI::error("Failed to synchronize files: " . $e->getMessage());
		}



		// Adjust ownership of the destination files
		$chown_message = WP_CLI::colorize("\n%YSTAGE 2:%n %CAdjusting File Ownership and Permissions on the Destination Environment%n");
		WP_CLI::log($chown_message);

		$fix_ownership_command = 'chown -R ' . $dest . ':' . $dest . ' ' . $dest_config['path_folder'];

		try {
			shell_exec($fix_ownership_command);
			WP_CLI::success("Ownership fixed");
		} catch (Exception $e) {
			WP_CLI::error("Failed to fix ownership: " . $e->getMessage());
		}

		$folder_permissions_command = 'find ' . escapeshellarg($dest_config['path_folder']) . ' -type d -print0 | xargs -0 chmod ' . escapeshellarg($folders);
		$file_permissions_command = 'find ' . escapeshellarg($dest_config['path_folder']) . ' -type f -print0 | xargs -0 chmod ' . escapeshellarg($files);

		try {
			shell_exec($folder_permissions_command);
			WP_CLI::success("Folder permissions adjusted");
		} catch (Exception $e) {
			WP_CLI::error("Failed to adjust folder permissions: " . $e->getMessage());
		}

		try {
			shell_exec($file_permissions_command);
			WP_CLI::success("File permissions adjusted");
		} catch (Exception $e) {
			WP_CLI::error("Failed to adjust file permissions: " . $e->getMessage());
		}

		$dump_database_message = WP_CLI::colorize("\n%YSTAGE 3:%n %CDumping Database from Staging Environment%n");
		WP_CLI::log($dump_database_message);

		$options = array(
			'return'       => 'stdout',
			'launch'       => true,
			'exit_error'   => false,
			'command_args' => [
				'--path=' . $src_config['path_folder'],
				'--allow-root'
			],
		);

		try {
			WP_CLI::runcommand('db export db.sql', $options);
			WP_CLI::success("Exported source database to db.sql");
		} catch (Exception $e) {
			WP_CLI::error("Failed to export source database: " . $e->getMessage());
		}


		// Clean the destination database
		$options = array(
			'return'       => 'stdout',
			'launch'       => true,
			'exit_error'   => false,
			'command_args' => [
				'--yes',
				'--path=' . $dest_config['path_folder'],
				'--allow-root'
			],
		);

		try {
			WP_CLI::runcommand('db clean', $options);
			WP_CLI::success("Cleaned destination database");
		} catch (Exception $e) {
			WP_CLI::error("Failed to drop database: " . $e->getMessage());
		}

		$import_database_message = WP_CLI::colorize("\n%YSTAGE 4:%n %CImporting Database to Destination Environment%n");
		WP_CLI::log($import_database_message);

		// Import the source database
		$options = array(
			'return'       => 'stdout',
			'launch'       => true,
			'exit_error'   => false,
			'command_args' => [
				'--path=' . $dest_config['path_folder'],
				'--allow-root'
			],
		);

		try {
			WP_CLI::runcommand('db import db.sql', $options);
			WP_CLI::success("Imported database from db.sql to destination database");
		} catch (Exception $e) {
			WP_CLI::error("Failed to import database: " . $e->getMessage());
		}


		$search_replace_message = WP_CLI::colorize("\n%YSTAGE 5:%n %CPerforming Search and Replace on Destination Database%n");
		WP_CLI::log($search_replace_message);


		// Perform a search and replace on the destination database
		$options = array(
			'return'       => 'stdout',
			'launch'       => true,
			'exit_error'   => false,
			'command_args' => [
				'--all-tables',
				'--path=' . $dest_config['path_folder'],
				'--allow-root'
			],
		);


		WP_CLI::log("From: " . $src_config['url']);
		WP_CLI::log("To:   " . $dest_config['url']);

		try {
			WP_CLI::runcommand('search-replace ' . $src_config['url'] . ' ' . $dest_config['url'], $options);
			WP_CLI::success("Search and replace complete");
		} catch (Exception $e) {
			WP_CLI::error("Failed to search and replace: " . $e->getMessage());
		}


		$completion_message = WP_CLI::colorize("\n\nAll Done!\n");
		WP_CLI::log($completion_message);
	}

	/**
	 * Synchronizes files between the source and destination WordPress installations.
	 *
	 * @param array $src_config  The source WordPress configuration, including the file path.
	 * @param array $dest_config The destination WordPress configuration, including the file path.
	 * @param array $assoc_args  Optional arguments to control which files (plugins, themes, uploads) are synchronized.
	 *
	 * @return bool Returns true on successful synchronization.
	 */
	private function synchronize_files($src_config, $dest_config, $assoc_args)
	{

		$option_plugins = isset($assoc_args['plugins']) ? $this->string_to_bool($assoc_args['plugins']) : true;
		$option_themes = isset($assoc_args['themes']) ? $this->string_to_bool($assoc_args['themes']) : true;
		$option_uploads = isset($assoc_args['uploads']) ? $this->string_to_bool($assoc_args['uploads']) : true;

		$sync_message = WP_CLI::colorize("\n\n%YSTAGE 1:%n %CSynchronizing File System%n");
		WP_CLI::log($sync_message);

		$options = [
			'--archive',
			'--recursive',
			'--compress',
			'--progress',
			// '--delete',
			'--exclude=wp-config.php',
		];

		if (!$option_plugins) {
			$options[] = '--exclude=wp-content/plugins/*';
		}
		if (!$option_themes) {
			$options[] = '--exclude=wp-content/themes/*';
		}
		if (!$option_uploads) {
			$options[] = '--exclude=wp-content/uploads/*';
		}

		$options_str = implode(' ', $options);

		$rsync_command = 'rsync ' . $options_str . ' ' . $src_config['path_folder'] . '/ ' . $dest_config['path_folder'];

		$file_count = 0;

		if (!$option_plugins) {
			WP_CLI::log("Skipping plugins");
			$file_count = $file_count - $this->get_file_count($src_config['path_folder'] . '/wp-content/plugins');
		}

		if (!$option_themes) {
			WP_CLI::log("Skipping themes");
			$file_count = $file_count - $this->get_file_count($src_config['path_folder'] . '/wp-content/themes');
		}

		if (!$option_uploads) {
			WP_CLI::log("Skipping uploads");
			$file_count = $file_count - $this->get_file_count($src_config['path_folder'] . '/wp-content/uploads');
		}

		$total_files = $file_count + $this->get_file_count($src_config['path_folder']);

		WP_CLI::log("Total files to sync: " . $total_files);

		// Start the progress bar
		$progress = \WP_CLI\Utils\make_progress_bar('Syncing files...', $total_files, $total_files);

		// Execute the rsync command and handle progress
		$this->execute_rsync_with_progress($rsync_command, $progress);

		// Finish the progress bar
		$progress->finish();

		return true;
	}



	/**
	 * Utility function to convert a string to a boolean value.
	 *
	 * @param [type] $value
	 * @return bool
	 */
	private function string_to_bool($value)
	{
		return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null
			? filter_var($value, FILTER_VALIDATE_BOOLEAN)
			: $value;
	}



	/**
	 * Count the number of files in a given folder
	 *
	 * @param string $dir
	 * @return int
	 */
	private function get_file_count($dir)
	{
		$file_count = 0;
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
		foreach ($files as $file) {
			if ($file->isFile()) {
				$file_count++;
			}
		}
		return $file_count;
	}


	/**
	 * Execute rsync and update the WP-CLI progress bar.
	 */
	private function execute_rsync_with_progress($rsync_command, $progress)
	{
		// Execute rsync with progress output
		$descriptorspec = [
			0 => ["pipe", "r"],   // stdin is a pipe that the child will read from
			1 => ["pipe", "w"],   // stdout is a pipe that the child will write to
			2 => ["pipe", "w"],   // stderr is a pipe that the child will write to
		];

		$process = proc_open($rsync_command, $descriptorspec, $pipes);

		if (is_resource($process)) {
			while (! feof($pipes[1])) {
				$output = fgets($pipes[1]);

				// Here you could parse $output to detect progress (for a more refined progress bar).
				// But for simplicity, we update the progress bar for every file.

				$progress->tick();
			}

			// Close the pipes
			fclose($pipes[0]);
			fclose($pipes[1]);
			fclose($pipes[2]);

			// Close the process
			proc_close($process);
		} else {
			WP_CLI::error("Error while executing rsync.");
		}
	}

	/**
	 * Finds a WordPress installation in the specified directory, retrieves its database name and site URL, and returns the relevant configuration details.
	 *
	 * @param string $src The source directory under `/home/` where the search for WordPress should start.
	 *
	 * @return array Returns an associative array with the following keys:
	 *               - 'path': The full path to the `wp-config.php` file.
	 *               - 'path_folder': The directory containing the `wp-config.php` file.
	 *               - 'db_name': The WordPress database name.
	 *               - 'url': The site URL of the WordPress installation.
	 *
	 * @throws \RuntimeException If the `wp-config.php` file cannot be found or any WP-CLI commands fail.
	 */

	private function find_wordpress($src)
	{

		$config = [];

		$path = "find /home/$src/htdocs -maxdepth 3 -name wp-config.php -print -quit";

		try {
			$wp_path = shell_exec($path);
		} catch (\Exception $e) {
			throw new \RuntimeException(esc_html("Failed to find WordPress installation in /home/$src/htdocs. Please check the source environment and try again."));
		}

		$wp_path_folder = dirname($wp_path);

		$options = array(
			'return'       => 'stdout',
			'launch'       => true,
			'exit_error'   => false,
			'command_args' => [
				'--path=' . $wp_path_folder,
				'--allow-root'
			],
		);

		try {
			$wp_db_name = WP_CLI::runcommand('config get DB_NAME', $options);
			$wp_url = WP_CLI::runcommand('option get siteurl', $options);
		} catch (\Exception $e) {
			throw new \RuntimeException("Failed to retrieve database name or site URL from WordPress installation. Please check the source environment and try again.");
		}

		$config['path'] = $wp_path;
		$config['path_folder'] = $wp_path_folder;
		$config['db_name'] = $wp_db_name;
		$config['url'] = $wp_url;

		return $config;
	}
}
