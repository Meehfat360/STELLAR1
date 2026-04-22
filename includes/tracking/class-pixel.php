<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles browser-side Meta Pixel injection and multi-channel tracking scripts.
 * Respects GDPR consent mode and lazy-load settings.
 */
class Stellar_Meta_Pixel {

    private static ?self $instance = null;
    private Stellar_Meta_Settings $settings;

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->settings = Stellar_Meta_Settings::instance();
        $this->hooks();
    }

    private function hooks(): void {
        // Inject pixel base code in <head>
        add_action( 'wp_head', [ $this, 'inject_pixel' ], 1 );
        // Inject multi-channel scripts
        add_action( 'wp_head', [ $this, 'inject_multichannel' ], 2 );
        // Enqueue frontend JS bridge
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        // Page-level events via inline script
        add_action( 'wp_footer', [ $this, 'inject_page_events' ], 20 );
    }

    public function inject_pixel(): void {
        if ( ! $this->settings->is_pixel_enabled() ) return;
        $pixel_id = $this->settings->pixel_id();
        if ( ! $pixel_id ) return;

        // In GDPR mode we output the pixel snippet inside a consent-gate wrapper
        $gdpr = $this->settings->is_gdpr_mode();
        $lazy = $this->settings->is_lazy_pixel() ? 'defer' : '';

        ?>
<!-- Stellar Meta :: Meta Pixel -->
<script <?php echo esc_attr( $lazy ); ?>>
(function(){
<?php if ( $gdpr ) : ?>
  if(typeof stellarConsentGranted === 'undefined' || !stellarConsentGranted){
    window.__stellarPixelPending = true; return;
  }
<?php endif; ?>
  !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
  n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}
  (window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
  fbq('init', '<?php echo esc_js( $pixel_id ); ?>');
  fbq('track', 'PageView');
  window.__stellarPixelLoaded = true;
}());
</script>
<noscript><img height="1" width="1" style="display:none"
  src="https://www.facebook.com/tr?id=<?php echo esc_attr( $pixel_id ); ?>&ev=PageView&noscript=1" alt=""/></noscript>
<!-- / Stellar Meta Pixel -->
        <?php
    }

    public function inject_multichannel(): void {
        // GA4
        if ( $ga4_id = $this->settings->ga4_measurement_id() ) {
            ?>
<!-- Stellar Meta :: GA4 -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $ga4_id ); ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?php echo esc_js( $ga4_id ); ?>');</script>
            <?php
        }

        // TikTok Pixel
        if ( $tt_id = $this->settings->tiktok_pixel_id() ) {
            ?>
<!-- Stellar Meta :: TikTok Pixel -->
<script>!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie"];ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e};ttq.load=function(e,n){var i="https://analytics.tiktok.com/i18n/pixel/events.js";ttq._i=ttq._i||{};ttq._i[e]=[];ttq._i[e]._u=i;ttq._t=ttq._t||{};ttq._t[e]=+new Date;ttq._o=ttq._o||{};ttq._o[e]=n||{};var o=document.createElement("script");o.type="text/javascript";o.async=!0;o.src=i+"?sdkid="+e+"&lib="+t;var a=document.getElementsByTagName("script")[0];a.parentNode.insertBefore(o,a)};ttq.load('<?php echo esc_js( $tt_id ); ?>');ttq.page();}(window,document,'ttq');</script>
            <?php
        }
    }

    public function enqueue_scripts(): void {
        $min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
        wp_enqueue_script(
            'stellar-meta-frontend',
            STELLAR_META_URL . "assets/js/frontend{$min}.js",
            [ 'jquery' ],
            STELLAR_META_VERSION,
            true
        );

        wp_localize_script( 'stellar-meta-frontend', 'StellarMeta', [
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'restUrl'     => rest_url( 'stellar-meta/v1/' ),
            'nonce'       => wp_create_nonce( 'stellar_meta_nonce' ),
            'pixelId'     => $this->settings->pixel_id(),
            'gdprMode'    => $this->settings->is_gdpr_mode(),
            'lazyPixel'   => $this->settings->is_lazy_pixel(),
            'platforms'   => $this->enabled_platforms(),
        ] );
    }

    public function inject_page_events(): void {
        // Emit structured page context for the JS bridge
        $context = $this->build_page_context();
        if ( empty( $context ) ) return;
        ?>
<script>
if(typeof StellarMeta !== 'undefined'){
  StellarMeta.pageContext = <?php echo wp_json_encode( $context ); ?>;
  if(typeof stellarMetaTrackPage === 'function') stellarMetaTrackPage(StellarMeta.pageContext);
}
</script>
        <?php
    }

    private function build_page_context(): array {
        $ctx = [ 'pageType' => 'other' ];

        if ( is_product() ) {
            global $post;
            $product = wc_get_product( $post );
            if ( $product ) {
                $ctx = [
                    'pageType'   => 'product',
                    'productId'  => $product->get_id(),
                    'productName'=> $product->get_name(),
                    'price'      => (float) $product->get_price(),
                    'currency'   => get_woocommerce_currency(),
                ];
            }
        } elseif ( is_cart() ) {
            $ctx = [ 'pageType' => 'cart' ];
        } elseif ( is_checkout() ) {
            $ctx = [ 'pageType' => 'checkout' ];
        } elseif ( is_shop() || is_product_category() ) {
            $ctx = [ 'pageType' => 'listing' ];
        }

        return $ctx;
    }

    private function enabled_platforms(): array {
        $platforms = [];
        if ( $this->settings->pixel_id() )          $platforms[] = 'meta';
        if ( $this->settings->ga4_measurement_id() ) $platforms[] = 'ga4';
        if ( $this->settings->google_ads_id() )      $platforms[] = 'google_ads';
        if ( $this->settings->tiktok_pixel_id() )    $platforms[] = 'tiktok';
        return $platforms;
    }
}
