<?php
/**
 * FileChi Storage Driver Interface
 *
 * Defines the common contract implemented by all remote storage transports
 * (SFTP, FTPS, and S3-compatible object storage).
 *
 * @package FileChi\Storage
 */

defined('ABSPATH') || exit;

interface FileChi_Storage_Interface {

	/**
	 * Uploads a local file to the remote destination path.
	 *
	 * @param string $local_file Absolute path to local file.
	 * @param string $remote_path Relative remote path/key.
	 * @return bool True on success, false on failure.
	 */
	public function upload($local_file, $remote_path);

	/**
	 * Uploads string/stream content directly to remote destination path.
	 *
	 * @param string $content File content in memory.
	 * @param string $remote_path Relative remote path/key.
	 * @param string $mime_type Optional MIME content type.
	 * @return bool True on success, false on failure.
	 */
	public function upload_content($content, $remote_path, $mime_type = '');

	/**
	 * Deletes a remote file by its relative path/key.
	 *
	 * @param string $remote_path Relative remote path/key.
	 * @return bool True on success, false on failure.
	 */
	public function delete($remote_path);

	/**
	 * Checks if a remote file exists at the relative path/key.
	 *
	 * @param string $remote_path Relative remote path/key.
	 * @return bool True if file exists, false otherwise.
	 */
	public function exists($remote_path);

	/**
	 * Returns the public HTTP/HTTPS URL for a remote file path.
	 *
	 * @param string $remote_path Relative remote path/key.
	 * @return string Public remote URL.
	 */
	public function get_url($remote_path);

	/**
	 * Generates a secure, time-limited signed URL for downloadable access.
	 *
	 * @param string $remote_path Relative remote path/key.
	 * @param int    $expires_in_seconds Expiration TTL in seconds (default: 900s / 15m).
	 * @return string Signed URL.
	 */
	public function get_signed_url($remote_path, $expires_in_seconds = 900);

	/**
	 * Performs a live round-trip connection test to verify credentials and accessibility.
	 *
	 * @return array ['success' => bool, 'message' => string, 'details' => mixed]
	 */
	public function test_connection();

	/**
	 * Streams a remote file directly to output in chunks without loading into PHP memory.
	 *
	 * @param string $remote_path Relative remote path/key.
	 * @param int    $chunk_size Chunk size in bytes (default 1048576 = 1 MiB).
	 * @return bool True on success, false on failure.
	 */
	public function stream_to_output($remote_path, $chunk_size = 1048576);

	/**
	 * Downloads a remote file directly into a local file destination.
	 *
	 * Streams content directly to disk without buffering the whole file in memory.
	 *
	 * @param string $remote_path Relative remote path/key.
	 * @param string $local_file Absolute path to local file.
	 * @param int    $filesize Optional known file size to scale transfer timeout.
	 * @return true|string True on success, or string reason code on failure.
	 */
	public function download($remote_path, $local_file, $filesize = 0);

	/**
	 *
	 * @return string Error message, or empty string if no error.
	 */
	public function get_last_error();

	/**
	 * Returns the size in bytes of a remote file, or -1 if not found or cannot be determined cheaply.
	 *
	 * @param string $remote_path Relative remote path/key.
	 * @return int Size in bytes, or -1 on error/not found.
	 */
	public function get_size($remote_path);

	/**
	 * Sets the database provider ID associated with this driver.
	 *
	 * @param int $provider_id Provider ID.
	 * @return void
	 */
	public function set_provider_id($provider_id);

	/**
	 * Returns the database provider ID associated with this driver.
	 *
	 * @return int Provider ID.
	 */
	public function get_provider_id();
}

