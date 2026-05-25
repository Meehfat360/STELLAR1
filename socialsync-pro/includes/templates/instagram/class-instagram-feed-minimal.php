<?php
/**
 * Instagram Feed Minimal Template
 *
 * @package SSP\Templates
 */

namespace SSP\Templates\Instagram;

use SSP\Templates\Abstract_Template;

/**
 * Class Instagram_Feed_Minimal
 *
 * White background, product image centered, thin border frame, price bottom-left, minimal sans-serif.
 * Dimensions: 1080×1080, Ratio: 1:1
 */
class Instagram_Feed_Minimal extends Abstract_Template {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->platform     = 'instagram';
		$this->key          = 'instagram-feed-minimal';
		$this->name         = 'Feed Minimal';
		$this->description  = 'Clean minimal feed post with centered product and price';
		$this->dimensions   = [ 1080, 1080 ];
		$this->aspect_ratio = '1:1';
		$this->colors       = [
			'primary'   => '#e4405f',
			'secondary' => '#ffffff',
			'accent'    => '#999999',
		];
		$this->font_style   = 'elegant-serif';
	}

	/**
	 * Render preview data
	 *
	 * @param array $product Product data.
	 * @param array $ai_content AI content.
	 *
	 * @return array
	 */
	public function render_preview_data( array $product, array $ai_content ) {
		return [
			'layout'              => 'feed-minimal',
			'background'          => $this->colors['secondary'],
			'border_width'        => '3px',
			'border_color'        => $this->colors['accent'],
			'border_opacity'      => 0.3,
			'image_url'           => $product['image_url'] ?? '',
			'image_padding'       => '20px',
			'price'               => $product['price'] ?? '$0',
			'price_position'      => 'bottom-left',
			'price_color'         => $this->colors['accent'],
			'product_name'        => $product['name'] ?? '',
			'product_name_color'  => $this->colors['accent'],
			'product_name_size'   => '14px',
		];
	}
}
