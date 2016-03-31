<?php
/*
Plugin Name: RAMP - Convert URLs
Description: Convert staging URLs to production URLs in post content during RAMP
Author: Crowd Favorite
Version: 1.0
Author URI: http://crowdfavorite.com/
*/

function cfcu_register_deploy_callbacks() {
	global $cfcu_deploy_callbacks;
	$cfcu_deploy_callbacks = new cfcu_deploy_callbacks;
	$cfcu_deploy_callbacks->register_deploy_callbacks();
}

add_action('cfd_admin_init', 'cfcu_register_deploy_callbacks');

// running on source environment
add_filter( 'ramp_get_preflight_extras', 'ramp_convert_urls_triggering_notification', 99 );

function ramp_convert_urls_triggering_notification( $extras ) {
	$new = array();
	$new['post-url-updater'] = array();
	$new['post-url-updater']['__message__'] = 'URLs in post content will be translated.';

	error_log( 'extras in source plugin ' . print_r($extras, true) );
	return array_merge( $extras, $new );
}

class cfcu_deploy_callbacks {
	protected $name = 'Post URL updater';
	protected $description = '';

	public function __construct() {
		$this->description = __('Update URLs in post content', 'cfcu');
	}

	public function register_deploy_callbacks() {
		cfd_register_deploy_callback($this->name, $this->description,
			array(
				'send_callback' => array($this, 'cfcu_send_callback'),
				'receive_callback' => array($this, 'cfcu_receive_callback'),
				'preflight_send_callback' => array($this, 'cfcu_preflight_send_callback'),
				'preflight_check_callback' => array($this, 'cfcu_preflight_check_callback'),
				'preflight_display_callback' => array($this, 'cfcu_preflight_display_callback'),
				'comparison_send_callback' => array($this, 'cfcu_comparison_send_callback'),
				'comparison_check_callback' => array($this, 'cfcu_comparison_check_callback'),
				'comparison_selection_row_callback' => array($this, 'cfcu_comparison_selection_row_callback'),
			)
		);
	}

	// -- Comparison (New Batch) callbacks

	public function cfcu_comparison_send_callback($batch_comparison_data) {
		return array('cfcu_config' => true);
	}

	public function cfcu_comparison_check_callback($data, $batch_items) {
		return array('cfcu_config' => $batch_items);
	}

	public function cfcu_comparison_selection_row_callback($compiled_data) {
		$ret = array(
			'selected' => true,
			'title' => $this->name,
			'message' => $this->description,
		);

		return $ret;
	}

	public function cfcu_preflight_send_callback($data) {
		$extra_id = cfd_make_callback_id($this->name);
		if (!(isset($data['extras']) &&
				isset($data['extras'][$extra_id]) &&
				in_array('cfcu_config', $data['extras'][$extra_id]))) {
			return null;
		}
		return array('cfcu_config' => true);
	}

	public function cfcu_preflight_check_callback($data, $batch_data) {
		$ret = array();
		$errors = array();

		if (isset($batch_data['post_types']) && !empty($batch_data['post_types'])) {
			$ret['__message__'] = __('URLs in post content will be translated.', 'cfcu');
		}
		else {
			$ret['__message__'] = __('No posts in batch, no action needed.', 'cfcu');
		}


		return $ret;
	}

	public function cfcu_preflight_display_callback($batch_preflight_data) {
		return $batch_preflight_data;
	}

// Transfer Callback Methods

	public function cfcu_send_callback($batch_data) {
		$ret = array();

		$extra_id = cfd_make_callback_id($this->name);

		$post_guids = array();
		if (!empty($batch_data['post_types'])) {
			foreach ($batch_data['post_types'] as $post_type => $posts) {
				if (!empty($posts)) {
					foreach ($posts as $guid => $post) {
						$post_guids[$guid] = true;
					}
				}
			}
		}
		if (!empty($post_guids)) {
			$staging_url = home_url('/', 'http');
			$staging_url = preg_replace('/^http:/', '', $staging_url);
			$ret['guids'] = $post_guids;
			$ret['staging_url'] = $staging_url;
		}
		return $ret;
	}

	public function cfcu_receive_callback($cfcu_settings) {
		global $wpdb;
		$success = true;
		$message = __('No posts to update', 'cfcu');
		$message = print_r($cfcu_settings, true);
		$messages = array();

		$prod_url = home_url('/', 'http');
		$prod_url = preg_replace('/^http:/', '', $prod_url);
		if (isset($cfcu_settings['guids']) && !empty($cfcu_settings['guids'])
				&& isset($cfcu_settings['staging_url']) && !empty($cfcu_settings['staging_url'])) {
			foreach ($cfcu_settings['guids'] as $guid => $true) {
				$post_id = cfd_get_post_id_by_guid($guid);
				$post = get_post($post_id);
				if (!$post) {
					$messages[] = "couldn't get post $post_id from guid $guid";
					continue;
				}


				$updated_post = array();
				$updated_post['post_content'] = str_replace($cfcu_settings['staging_url'], $prod_url, $post->post_content);
				$updated_post['post_excerpt'] = str_replace($cfcu_settings['staging_url'], $prod_url, $post->post_excerpt);
				$result = $wpdb->update($wpdb->posts, $updated_post, array('ID' => $post->ID));

				if ($result === false) {
					$success = false;
					$messages[] = sprintf(__('error updating %s %d (%s)', 'cfcu'), $post->post_type, $post->ID, $post->post_title);
				}
				else {
					$messages[] = sprintf(__('updated %s %d (%s)', 'cfcu'), $post->post_type, $post->ID, $post->post_title);
				}
			}

		}
		if (!empty($messages)) {
			$message = sprintf(__('Results: %s', 'cfcu'), implode('; ', $messages));
		}

		return array(
			'success' => $success,
			'message' => $message
		);
	}
}

