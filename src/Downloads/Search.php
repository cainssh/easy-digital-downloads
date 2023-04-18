<?php
/**
 * Search functionality for downloads.
 */
namespace EDD\Downloads;

use WP_Query;

class Search {

	/**
	 * Retrieve a downloads drop down
	 *
	 * @since 3.1.0.5 Copied from `edd_ajax_download_search`
	 *
	 * @return void
	 */
	public function ajax_search() {
		$search = $this->get_search_data();

		$new_search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

		// Limit to only alphanumeric characters, including unicode and spaces.
		$new_search = preg_replace('/[^\pL^\pN\pZ]/', ' ', $new_search);

		if ($search['text'] === $new_search) {
			echo wp_json_encode($search['results']);
			edd_die();
		}

		$search['text'] = $new_search;
		$excludes = isset($_GET['current_id']) ? array_unique(array_map('absint', (array)$_GET['current_id'])) : array();
		$no_bundles = isset($_GET['no_bundles']) ? filter_var($_GET['no_bundles'], FILTER_VALIDATE_BOOLEAN) : false;
		$variations = isset($_GET['variations']) ? filter_var($_GET['variations'], FILTER_VALIDATE_BOOLEAN) : false;
		$variations_only = isset($_GET['variations_only']) ? filter_var($_GET['variations_only'], FILTER_VALIDATE_BOOLEAN) : false;

		$status = !current_user_can('edit_products') ?
			apply_filters('edd_product_dropdown_status_nopriv', array('publish')) :
			apply_filters('edd_product_dropdown_status', array('publish', 'draft', 'private', 'future'));

		$args = array(
			'orderby'          => 'title',
			'order'            => 'ASC',
			'post_type'        => 'download',
			'posts_per_page'   => 50,
			'post_status'      => implode(',', $status),
			'post__not_in'     => $excludes,
			'edd_search'       => $new_search,
			'suppress_filters' => false,
		);

		if ($no_bundles) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => '_edd_product_type',
					'value'   => 'bundle',
					'compare' => '!=',
				),
				array(
					'key'     => '_edd_product_type',
					'value'   => 'bundle',
					'compare' => 'NOT EXISTS',
				),
			);
		}

		add_filter('posts_where', array($this, 'filter_where'), 10, 2);
		$items = get_posts($args);
		remove_filter('posts_where', array($this, 'filter_where'), 10, 2);

		$search['results'] = $this->prepare_search_results($items, $variations, $variations_only);
		$this->set_search_data($search);

		echo wp_json_encode($search['results']);
		edd_die();
	}

	/**
	 * Get search data from transient or initialize with default values.
	 *
	 * @return array
	 */
	protected function get_search_data() {
		$args = get_transient('edd_download_search');

		return wp_parse_args(
			(array)$args,
			array(
				'text'    => '',
				'results' => array
		));
	}

	/**
	 * Set search data to transient.
	 *
	 * @param array $search
	 * @return void
	 */
	protected function set_search_data($search) {
		set_transient('edd_download_search', $search, 30);
	}

	/**
	 * Prepare search results.
	 *
	 * @param array $items
	 * @param bool  $variations
	 * @param bool  $variations_only
	 * @return array
	 */
	protected function prepare_search_results($items, $variations, $variations_only) {
		$results = array();

		if (!empty($items)) {
			$items = wp_list_pluck($items, 'post_title', 'ID');

			foreach ($items as $post_id => $title) {
				$product_title = $title;
				$prices = edd_get_variable_prices($post_id);

				if (!empty($prices) && (false === $variations || !$variations_only)) {
					$title .= ' (' . __('All Price Options', 'easy-digital-downloads') . ')';
				}

				if (empty($prices) || !$variations_only) {
					$results[] = array(
						'id'   => $post_id,
						'name' => $title,
					);
				}

				if (!empty($variations) && !empty($prices)) {
					foreach ($prices as $key => $value) {
						$name = !empty($value['name']) ? $value['name'] : '';

						if (!empty($name)) {
							$results[] = array(
								'id'   => $post_id . '_' . $key,
								'name' => esc_html($product_title . ': ' . $name),
							);
						}
					}
				}
			}
		}

		return $results;
	}

	/**
	 * Filters the WHERE SQL query for the edd_download_search.
	 * This searches the download titles only, not the excerpt/content.
	 *
	 * @since 3.1.0.2
	 * @since 3.1.0.5 Moved to EDD\Downloads\Ajax.
	 * @param string $where
	 * @param WP_Query $wp_query
	 * @return string
	 */
	public function filter_where($where, $wp_query) {
		$search = $wp_query->get('edd_search');
		if (!$search) {
			return $where;
		}

		$terms = $this->parse_search_terms($search);
		if (empty($terms)) {
			return $where;
		}

		global $wpdb;
		$query = '';
		foreach ($terms as $term) {
			$operator = empty($query) ? '' : ' AND ';
			$term     = $wpdb->esc_like($term);
			$query   .= "{$operator}{$wpdb->posts}.post_title LIKE '%{$term}%'";
		}
		if ($query) {
			$where .= " AND ({$query})";
		}

		return $where;
	}

	/**
	 * Parses the search terms to allow for a "fuzzy" search.
	 *
	 * @since 3.1.0.5
	 * @param string $search
	 * @return array
	 */
	protected function parse_search_terms($search) {
		$terms      = explode(' ', $search);
		$strtolower = function_exists('mb_strtolower') ? 'mb_strtolower' : 'strtolower';
		$checked    = array();

		foreach ($terms as $term) {
		// Keep before/after spaces when term is for exact match.
		if (preg_match('/^".+"$/', $term)) {
			$term = trim($term, "\"'");
		} else {
			$term = trim($term, "\"' ");
		}

		// Avoid single A-Z and single dashes.
		if (!$term || (1 === strlen($term) && preg_match('/^[a-z\-]$/i', $term))) {
			continue;
		}

		$checked[] = $term;
	}

	return $checked;
}

