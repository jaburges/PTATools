<?php
/**
 * Microsoft Graph API handler for OneDrive Media functionality
 * Handles file operations, folder management, and public link generation
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_OneDrive_Media_GraphAPI {
    
    private $auth;
    private $base_folder;
    private $storage_type; // 'onedrive' or 'sharepoint'
    private $site_id;
    private $drive_id;
    
    public function __construct() {
        if (class_exists('Azure_OneDrive_Media_Auth')) {
            $this->auth = new Azure_OneDrive_Media_Auth();
        }
        
        $this->storage_type = Azure_Settings::get_setting('onedrive_media_storage_type', 'onedrive');
        $this->base_folder = Azure_Settings::get_setting('onedrive_media_base_folder', 'WordPress Media');
        $this->site_id = Azure_Settings::get_setting('onedrive_media_site_id', '');
        $this->drive_id = Azure_Settings::get_setting('onedrive_media_drive_id', '');
    }
    
    /**
     * Get the base API URL based on storage type
     */
    private function get_base_api_url() {
        if ($this->storage_type === 'sharepoint' && $this->site_id && $this->drive_id) {
            return "https://graph.microsoft.com/v1.0/sites/{$this->site_id}/drives/{$this->drive_id}";
        }
        
        return 'https://graph.microsoft.com/v1.0/me/drive';
    }
    
    /**
     * Upload file to OneDrive
     * Supports large file uploads using upload session
     */
    public function upload_file($local_path, $remote_path, $file_name) {
        if (!$this->auth) {
            return false;
        }
        
        $access_token = $this->auth->get_access_token();
        if (!$access_token) {
            Azure_Logger::error('OneDrive Media API: No access token for file upload');
            return false;
        }
        
        // Get file size to determine upload method
        $file_size = filesize($local_path);
        
        // Use simple upload for files under 4MB
        if ($file_size < 4 * 1024 * 1024) {
            return $this->simple_upload($local_path, $remote_path, $file_name, $access_token);
        }
        
        // Use resumable upload for larger files
        return $this->resumable_upload($local_path, $remote_path, $file_name, $access_token, $file_size);
    }
    
    /**
     * Simple upload for small files (< 4MB)
     */
    private function simple_upload($local_path, $remote_path, $file_name, $access_token) {
        $base_url = $this->get_base_api_url();
        $full_path = $this->combine_paths($remote_path, $file_name);
        $api_url = "{$base_url}/root:/{$full_path}:/content";
        
        $file_content = file_get_contents($local_path);
        
        $response = wp_remote_request($api_url, array(
            'method' => 'PUT',
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => mime_content_type($local_path)
            ),
            'body' => $file_content,
            'timeout' => 300
        ));
        
        if (is_wp_error($response)) {
            Azure_Logger::error('OneDrive Media API: Upload failed - ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code === 200 || $response_code === 201) {
            $file_data = json_decode($response_body, true);
            Azure_Logger::info('OneDrive Media API: File uploaded successfully - ' . $file_name);
            return $this->format_file_data($file_data);
        }
        
        Azure_Logger::error('OneDrive Media API: Upload failed with status ' . $response_code . ': ' . $response_body);
        return false;
    }
    
    /**
     * Resumable upload for large files (>= 4MB)
     */
    private function resumable_upload($local_path, $remote_path, $file_name, $access_token, $file_size) {
        $base_url = $this->get_base_api_url();
        $full_path = $this->combine_paths($remote_path, $file_name);
        
        // Create upload session
        $session_url = "{$base_url}/root:/{$full_path}:/createUploadSession";
        
        $response = wp_remote_post($session_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'item' => array(
                    '@microsoft.graph.conflictBehavior' => 'replace'
                )
            )),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            Azure_Logger::error('OneDrive Media API: Failed to create upload session - ' . $response->get_error_message());
            return false;
        }
        
        $session_data = json_decode(wp_remote_retrieve_body($response), true);
        $upload_url = $session_data['uploadUrl'] ?? '';
        
        if (empty($upload_url)) {
            Azure_Logger::error('OneDrive Media API: No upload URL in session response');
            return false;
        }
        
        // Upload file in chunks
        $chunk_size = 10 * 1024 * 1024; // 10MB chunks
        $file_handle = fopen($local_path, 'rb');
        $byte_position = 0;
        
        while (!feof($file_handle)) {
            $chunk_data = fread($file_handle, $chunk_size);
            $chunk_length = strlen($chunk_data);
            
            if ($chunk_length === 0) {
                break;
            }
            
            $byte_end = $byte_position + $chunk_length - 1;
            
            $response = wp_remote_request($upload_url, array(
                'method' => 'PUT',
                'headers' => array(
                    'Content-Length' => $chunk_length,
                    'Content-Range' => "bytes {$byte_position}-{$byte_end}/{$file_size}"
                ),
                'body' => $chunk_data,
                'timeout' => 300
            ));
            
            if (is_wp_error($response)) {
                fclose($file_handle);
                Azure_Logger::error('OneDrive Media API: Chunk upload failed - ' . $response->get_error_message());
                return false;
            }
            
            $byte_position += $chunk_length;
        }
        
        fclose($file_handle);
        
        // Get final response
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200 || $response_code === 201) {
            $file_data = json_decode(wp_remote_retrieve_body($response), true);
            Azure_Logger::info('OneDrive Media API: Large file uploaded successfully - ' . $file_name);
            return $this->format_file_data($file_data);
        }
        
        Azure_Logger::error('OneDrive Media API: Upload failed with status ' . $response_code);
        return false;
    }
    
    /**
     * Delete file from OneDrive
     */
    public function delete_file($file_id) {
        if (!$this->auth) {
            return false;
        }
        
        $access_token = $this->auth->get_access_token();
        if (!$access_token) {
            Azure_Logger::error('OneDrive Media API: No access token for file deletion');
            return false;
        }
        
        $base_url = $this->get_base_api_url();
        $api_url = "{$base_url}/items/{$file_id}";
        
        $response = wp_remote_request($api_url, array(
            'method' => 'DELETE',
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            Azure_Logger::error('OneDrive Media API: Delete failed - ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 204) {
            Azure_Logger::info('OneDrive Media API: File deleted successfully - ' . $file_id);
            return true;
        }
        
        Azure_Logger::error('OneDrive Media API: Delete failed with status ' . $response_code);
        return false;
    }
    
    /**
     * List files in a folder
     */
    public function list_folder($folder_path = '') {
        if (!$this->auth) {
            return array();
        }
        
        $access_token = $this->auth->get_access_token();
        if (!$access_token) {
            Azure_Logger::error('OneDrive Media API: No access token for listing folder');
            return array();
        }
        
        $base_url = $this->get_base_api_url();
        
        if (empty($folder_path)) {
            $api_url = "{$base_url}/root/children";
        } else {
            $api_url = "{$base_url}/root:/{$folder_path}:/children";
        }
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            Azure_Logger::error('OneDrive Media API: List folder failed - ' . $response->get_error_message());
            return array();
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            Azure_Logger::error('OneDrive Media API: List folder failed with status ' . $response_code);
            return array();
        }
        
        $data = json_decode($response_body, true);
        $items = $data['value'] ?? array();

        // Follow pagination (@odata.nextLink) to get all items
        while (!empty($data['@odata.nextLink'])) {
            $next_response = wp_remote_get($data['@odata.nextLink'], array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type'  => 'application/json'
                ),
                'timeout' => 30
            ));
            if (is_wp_error($next_response) || wp_remote_retrieve_response_code($next_response) !== 200) {
                break;
            }
            $data = json_decode(wp_remote_retrieve_body($next_response), true);
            $items = array_merge($items, $data['value'] ?? array());
        }

        $formatted_items = array();
        foreach ($items as $item) {
            $formatted_items[] = $this->format_file_data($item);
        }
        
        return $formatted_items;
    }
    
    /**
     * Create folder in OneDrive
     */
    public function create_folder($parent_path, $folder_name) {
        if (!$this->auth) {
            return false;
        }
        
        $access_token = $this->auth->get_access_token();
        if (!$access_token) {
            Azure_Logger::error('OneDrive Media API: No access token for folder creation');
            return false;
        }
        
        $base_url = $this->get_base_api_url();
        
        if (empty($parent_path)) {
            $api_url = "{$base_url}/root/children";
        } else {
            $api_url = "{$base_url}/root:/{$parent_path}:/children";
        }
        
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'name' => $folder_name,
                'folder' => new stdClass(),
                '@microsoft.graph.conflictBehavior' => 'rename'
            )),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            Azure_Logger::error('OneDrive Media API: Create folder failed - ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 201) {
            $folder_data = json_decode(wp_remote_retrieve_body($response), true);
            Azure_Logger::info('OneDrive Media API: Folder created successfully - ' . $folder_name);
            return $folder_data;
        }
        
        Azure_Logger::error('OneDrive Media API: Create folder failed with status ' . $response_code);
        return false;
    }
    
    /**
     * Generate public sharing link
     */
    public function create_sharing_link($file_id, $link_type = 'view', $scope = 'anonymous') {
        if (!$this->auth) {
            return false;
        }
        
        $access_token = $this->auth->get_access_token();
        if (!$access_token) {
            Azure_Logger::error('OneDrive Media API: No access token for sharing link');
            return false;
        }
        
        $base_url = $this->get_base_api_url();
        $api_url = "{$base_url}/items/{$file_id}/createLink";
        
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'type' => $link_type,
                'scope' => $scope
            )),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            Azure_Logger::error('OneDrive Media API: Create sharing link failed - ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code === 200 || $response_code === 201) {
            $link_data = json_decode($response_body, true);
            return $link_data['link']['webUrl'] ?? false;
        }
        
        Azure_Logger::error('OneDrive Media API: Create sharing link failed with status ' . $response_code);
        return false;
    }
    
    /**
     * Get file thumbnails
     */
    public function get_thumbnails($file_id, $size = 'large') {
        if (!$this->auth) {
            return false;
        }
        
        $access_token = $this->auth->get_access_token();
        if (!$access_token) {
            return false;
        }
        
        $base_url = $this->get_base_api_url();
        $api_url = "{$base_url}/items/{$file_id}/thumbnails";
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            $thumbnails = $data['value'][0] ?? array();
            
            return array(
                'small' => $thumbnails['small']['url'] ?? '',
                'medium' => $thumbnails['medium']['url'] ?? '',
                'large' => $thumbnails['large']['url'] ?? ''
            );
        }
        
        return false;
    }
    
    /**
     * Get direct download URL
     */
    public function get_download_url($file_id) {
        if (!$this->auth) {
            return false;
        }
        
        $access_token = $this->auth->get_access_token();
        if (!$access_token) {
            return false;
        }
        
        $base_url = $this->get_base_api_url();
        $api_url = "{$base_url}/items/{$file_id}?select=@microsoft.graph.downloadUrl";
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            return $data['@microsoft.graph.downloadUrl'] ?? false;
        }
        
        return false;
    }
    
    /**
     * Search files in OneDrive
     */
    public function search_files($query) {
        if (!$this->auth) {
            return array();
        }
        
        $access_token = $this->auth->get_access_token();
        if (!$access_token) {
            return array();
        }
        
        $base_url = $this->get_base_api_url();
        $api_url = "{$base_url}/root/search(q='" . urlencode($query) . "')";
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array();
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            $items = $data['value'] ?? array();
            
            $formatted_items = array();
            foreach ($items as $item) {
                // Only return files, not folders
                if (!isset($item['folder'])) {
                    $formatted_items[] = $this->format_file_data($item);
                }
            }
            
            return $formatted_items;
        }
        
        return array();
    }
    
    /**
     * Format file data for consistent structure
     */
    private function format_file_data($file_data) {
        return array(
            'id' => $file_data['id'] ?? '',
            'name' => $file_data['name'] ?? '',
            'size' => $file_data['size'] ?? 0,
            'mime_type' => $file_data['file']['mimeType'] ?? '',
            'created' => $file_data['createdDateTime'] ?? '',
            'modified' => $file_data['lastModifiedDateTime'] ?? '',
            'web_url' => $file_data['webUrl'] ?? '',
            'download_url' => $file_data['@microsoft.graph.downloadUrl'] ?? '',
            'parent_path' => $file_data['parentReference']['path'] ?? '',
            'is_folder' => isset($file_data['folder'])
        );
    }
    
    /**
     * Combine paths safely
     */
    private function combine_paths($base, $path) {
        $base = trim($base, '/');
        $path = trim($path, '/');
        
        if (empty($base)) {
            return $path;
        }
        
        if (empty($path)) {
            return $base;
        }
        
        return $base . '/' . $path;
    }
    
    /**
     * Get SharePoint sites
     */
    public function get_sharepoint_sites($search = '') {
        if (!$this->auth) {
            return array();
        }
        
        $access_token = $this->auth->get_access_token();
        if (!$access_token) {
            return array();
        }
        
        $api_url = 'https://graph.microsoft.com/v1.0/sites';
        if (!empty($search)) {
            $api_url .= '?search=' . urlencode($search);
        }
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array();
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['value'] ?? array();
    }
    
    /**
     * Get drives for a SharePoint site
     */
    public function get_site_drives($site_id) {
        if (!$this->auth) {
            return array();
        }
        
        $access_token = $this->auth->get_access_token();
        if (!$access_token) {
            return array();
        }
        
        $api_url = "https://graph.microsoft.com/v1.0/sites/{$site_id}/drives";
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array();
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['value'] ?? array();
    }
    
    /**
     * Get SharePoint site by URL
     */
    public function get_site_by_url($site_url) {
        if (!$this->auth) {
            return null;
        }
        
        $access_token = $this->auth->get_access_token();
        if (!$access_token) {
            return null;
        }
        
        // Parse the site URL to get the hostname and site path
        // Example: https://contoso.sharepoint.com/sites/marketing
        $parsed_url = parse_url($site_url);
        $hostname = $parsed_url['host'] ?? '';
        $path = isset($parsed_url['path']) ? trim($parsed_url['path'], '/') : '';
        
        if (empty($hostname)) {
            return null;
        }
        
        // Use Microsoft Graph API to get site by hostname and path
        // Format: /sites/{hostname}:/{server-relative-path}
        if (!empty($path)) {
            $api_url = "https://graph.microsoft.com/v1.0/sites/{$hostname}:/{$path}";
        } else {
            $api_url = "https://graph.microsoft.com/v1.0/sites/{$hostname}";
        }
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data ?: null;
    }
    
    /**
     * List folders in a specific drive
     */
    public function list_drive_folder($drive_id, $folder_path = '') {
        if (!$this->auth) {
            return array();
        }
        
        $access_token = $this->auth->get_access_token();
        if (!$access_token) {
            return array();
        }
        
        // Build API URL for drive items
        if (empty($folder_path) || $folder_path === '/') {
            $api_url = "https://graph.microsoft.com/v1.0/drives/{$drive_id}/root/children";
        } else {
            $folder_path = ltrim($folder_path, '/');
            $api_url = "https://graph.microsoft.com/v1.0/drives/{$drive_id}/root:/{$folder_path}:/children";
        }
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array();
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $items = $data['value'] ?? array();
        
        // Format items to match expected structure
        $folders = array();
        foreach ($items as $item) {
            if (isset($item['folder'])) {
                $folders[] = array(
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'path' => $item['parentReference']['path'] ?? '' . '/' . $item['name'],
                    'is_folder' => true
                );
            }
        }
        
        return $folders;
    }
}