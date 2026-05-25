<?php
/**
 * SocialSync Pro - Template Engine & Abstract Base
 *
 * @package SSP\Templates
 */

namespace SSP\Templates;

/**
 * Abstract Template Base Class
 *
 * @abstract
 * @class Abstract_Template
 */
abstract class Abstract_Template {

	/**
	 * Platform (facebook, instagram, pinterest)
	 *
	 * @var string
	 */
	public $platform;

	/**
	 * Template key/slug
	 *
	 * @var string
	 */
	public $key;

	/**
	 * Template display name
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Template description
	 *
	 * @var string
	 */
	public $description;

	/**
	 * Dimensions [width, height]
	 *
	 * @var array
	 */
	public $dimensions = [ 1200, 628 ];

	/**
	 * Aspect ratio string
	 *
	 * @var string
	 */
	public $aspect_ratio = '1.91:1';

	/**
	 * Color scheme
	 *
	 * @var array
	 */
	public $colors = [
		'primary'   => '#000000',
		'secondary' => '#ffffff',
		'accent'    => '#ff0000',
	];

	/**
	 * Font style
	 *
	 * @var string
	 */
	public $font_style = 'bold-modern';

	/**
	 * Render preview data for React component
	 *
	 * @param array $product Product data.
	 * @param array $ai_content AI-generated content.
	 *
	 * @return array Preview data.
	 */
	abstract public function render_preview_data( array $product, array $ai_content );

	/**
	 * Get CSS variables for preview rendering
	 *
	 * @return array
	 */
	public function get_css_variables() {
		return [
			'--color-primary'   => $this->colors['primary'],
			'--color-secondary' => $this->colors['secondary'],
			'--color-accent'    => $this->colors['accent'],
			'--font-style'      => $this->font_style,
			'--width'           => $this->dimensions[0] . 'px',
			'--height'          => $this->dimensions[1] . 'px',
		];
	}

	/**
	 * Get template metadata
	 *
	 * @return array
	 */
	public function get_metadata() {
		return [
			'key'          => $this->key,
			'name'         => $this->name,
			'description'  => $this->description,
			'platform'     => $this->platform,
			'dimensions'   => $this->dimensions,
			'aspect_ratio' => $this->aspect_ratio,
			'colors'       => $this->colors,
			'font_style'   => $this->font_style,
		];
	}
}
