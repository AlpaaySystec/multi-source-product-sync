<?php
/**
 * ProductDTO - Standardized product data structure and normalization helper.
 *
 * Usage:
 *   $dto = ProductDTO::normalize($raw_extracted_data);
 */

if (!defined('ABSPATH')) {
    exit;
}

class ProductDTO {

    /**
     * Normalize an extracted product data array to the standard ProductDTO format.
     *
     * @param array $data Raw extracted data.
     * @return array
     */
    public static function normalize(array $data) {
        $product_type   = in_array($data['product_type'] ?? '', array('simple', 'variable'), true) ? $data['product_type'] : 'simple';
        $variations     = self::normalize_variations(isset($data['variations']) ? $data['variations'] : array());
		$regular_price  = isset($data['regular_price']) ? intval($data['regular_price']) : 0;
		$sale_price     = self::normalize_sale_price(isset($data['sale_price']) ? $data['sale_price'] : null);
        $stock_quantity = self::normalize_stock_quantity(array_key_exists('stock_quantity', $data) ? $data['stock_quantity'] : null);
        $manage_stock   = self::normalize_manage_stock($data, $stock_quantity);
		$stock_status   = self::normalize_stock_status(isset($data['stock_status']) ? $data['stock_status'] : '');

        // WooCommerce inventory for a variable product belongs to its variations unless
        // the source explicitly models parent-level inventory and has no child payload.
        if ('variable' === $product_type && !empty($variations)) {
            $stock_quantity = null;
            $manage_stock   = false;
        }

		// A zero effective source price is never sellable. Keep inventory-management
		// semantics intact, but make the DTO safe before any importer consumes it.
		if (!self::has_positive_effective_price($regular_price, $sale_price, $product_type, $variations)) {
			$stock_status = 'out-of-stock';
			if (true === $manage_stock) {
				$stock_quantity = 0;
			}
		}

        // Base structure with defaults
        $dto = array(
            'product_id'      => isset($data['product_id']) ? (string) $data['product_id'] : '',
            'sku'             => isset($data['sku']) ? (string) $data['sku'] : '',
            'title'           => isset($data['title']) ? (string) $data['title'] : '',
            'excerpt'         => self::strip_hyperlinks( isset($data['excerpt']) ? (string) $data['excerpt'] : '' ),
            'content'         => self::strip_hyperlinks( isset($data['content']) ? (string) $data['content'] : '' ),
            'featured_image'  => isset($data['featured_image']) ? (string) $data['featured_image'] : '',
            'gallery_images'  => isset($data['gallery_images']) && is_array($data['gallery_images']) ? array_values(array_map('strval', $data['gallery_images'])) : array(),
			'regular_price'   => $regular_price,
			'sale_price'      => $sale_price,
            'currency'        => !empty($data['currency']) ? (string) $data['currency'] : 'تومان',
			'stock_status'    => $stock_status,
            'stock_quantity'  => $stock_quantity,
			'manage_stock'    => $manage_stock,
            'categories'      => isset($data['categories']) && is_array($data['categories']) ? array_values(array_map('strval', $data['categories'])) : array(),
            'tags'            => isset($data['tags']) && is_array($data['tags']) ? array_values(array_map('strval', $data['tags'])) : array(),
            'product_type'    => $product_type,
            'attributes'      => self::normalize_attributes(isset($data['attributes']) ? $data['attributes'] : array()),
            'variations'      => $variations,
        );

        return $dto;
    }

    /**
     * Remove all hyperlinks (<a> tags) from a string, keeping inner text/HTML.
     *
     * @param string $text
     * @return string
     */
    private static function strip_hyperlinks($text) {
        if (empty($text)) {
            return $text;
        }
        // Remove <a ...> ... </a> but keep the content inside the tag
        return preg_replace('/<a\b[^>]*>(.*?)<\/a>/is', '$1', $text);
    }

    /**
     * Normalize sale price to int|null.
     */
    private static function normalize_sale_price($price) {
        if ($price === null || $price === '' || $price === false) {
            return null;
        }
        $price = intval($price);
        return $price > 0 ? $price : null;
    }

	/**
	 * A positive sale or regular price makes an item sellable. For a variable
	 * parent, any positively priced child also makes the parent sellable.
	 */
	private static function has_positive_effective_price($regular_price, $sale_price, $product_type = 'simple', $variations = array()) {
		if ((is_numeric($sale_price) && (float) $sale_price > 0) || (is_numeric($regular_price) && (float) $regular_price > 0)) {
			return true;
		}

		if ('variable' === $product_type) {
			foreach ((array) $variations as $variation) {
				if ((is_numeric($variation['sale_price'] ?? null) && (float) $variation['sale_price'] > 0)
					|| (is_numeric($variation['regular_price'] ?? null) && (float) $variation['regular_price'] > 0)) {
					return true;
				}
			}
		}

		return false;
	}

    /**
     * Normalize stock status without inventing availability when the source is silent.
     */
    private static function normalize_stock_status($status) {
        $status = strtolower(trim((string) $status));
        $mapping = array(
            'instock'     => 'in-stock',
            'in-stock'    => 'in-stock',
            'outofstock'  => 'out-of-stock',
            'out-stock'   => 'out-of-stock',
            'out_of_stock'=> 'out-of-stock',
            '1'           => 'in-stock',
            'true'        => 'in-stock',
            'available'   => 'in-stock',
			'out-of-stock'=> 'out-of-stock',
			'0'           => 'out-of-stock',
			'false'       => 'out-of-stock',
			'unavailable' => 'out-of-stock',
			'unknown'     => 'unknown',
        );
        if (isset($mapping[$status])) {
            return $mapping[$status];
        }
        return 'unknown';
    }

    /**
     * A negative quantity is a common upstream sentinel, not importable WooCommerce stock.
     */
    private static function normalize_stock_quantity($quantity) {
        if (null === $quantity || '' === $quantity || false === $quantity || !is_numeric($quantity)) {
            return null;
        }
        $quantity = intval($quantity);
        return $quantity >= 0 ? $quantity : null;
    }

    /**
     * Preserve unknown inventory-management state as null. A concrete quantity implies
     * managed stock because WooCommerce cannot store a quantity while management is off.
     */
    private static function normalize_manage_stock($data, $quantity) {
        if (null !== $quantity) {
            return true;
        }
        if (!array_key_exists('manage_stock', $data) || null === $data['manage_stock'] || '' === $data['manage_stock']) {
            return null;
        }
        return (bool) $data['manage_stock'];
    }

    /**
     * Normalize attributes array.
     */
    private static function normalize_attributes($attributes) {
        if (!is_array($attributes)) {
            return array();
        }
        $normalized = array();
        foreach ($attributes as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $normalized[] = array(
                'id'                  => isset($attr['id']) ? intval($attr['id']) : 0,
                'name'                => isset($attr['name']) ? (string) $attr['name'] : '',
                'values'              => isset($attr['values']) && is_array($attr['values']) ? array_values(array_map('strval', $attr['values'])) : array(),
                'option_details'      => isset($attr['option_details']) && is_array($attr['option_details']) ? $attr['option_details'] : array(),
                'used_for_variations' => !empty($attr['used_for_variations']),
            );
        }
        return $normalized;
    }

    /**
     * Normalize variations array.
     */
    private static function normalize_variations($variations) {
        if (!is_array($variations)) {
            return array();
        }
        $normalized = array();
        foreach ($variations as $var) {
            if (!is_array($var)) {
                continue;
            }
            $stock_quantity = self::normalize_stock_quantity(array_key_exists('stock_quantity', $var) ? $var['stock_quantity'] : null);
            $manage_stock   = self::normalize_manage_stock($var, $stock_quantity);
			$regular_price  = isset($var['regular_price']) ? intval($var['regular_price']) : 0;
			$sale_price     = self::normalize_sale_price(isset($var['sale_price']) ? $var['sale_price'] : null);
			$stock_status   = self::normalize_stock_status(isset($var['stock_status']) ? $var['stock_status'] : '');
			if (!self::has_positive_effective_price($regular_price, $sale_price)) {
				$stock_status = 'out-of-stock';
				if (true === $manage_stock) {
					$stock_quantity = 0;
				}
			}
            $normalized[] = array(
                'attributes_summary' => isset($var['attributes_summary']) ? (string) $var['attributes_summary'] : '',
                'attributes_map'     => isset($var['attributes_map']) && is_array($var['attributes_map']) ? $var['attributes_map'] : (object) array(),
                'sku'                => isset($var['sku']) ? (string) $var['sku'] : '',
				'regular_price'      => $regular_price,
				'sale_price'         => $sale_price,
				'stock_status'       => $stock_status,
				'stock_quantity'     => $stock_quantity,
				'manage_stock'       => $manage_stock,
                'image'              => isset($var['image']) ? (string) $var['image'] : '',
            );
        }
        return $normalized;
    }
}
