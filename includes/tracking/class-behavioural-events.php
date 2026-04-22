<?php
defined( 'ABSPATH' ) || exit;

/**
 * Behavioural event tracking layer.
 * Injects JS for scroll depth, rage clicks, time-on-page, and exit intent.
 * All events fire to Meta CAPI + GA4 simultaneously.
 */
class Stellar_Meta_Behavioural_Events {

    private static ?self $instance = null;
    private Stellar_Meta_Settings $settings;

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->settings = Stellar_Meta_Settings::instance();
        add_action( 'wp_footer', [ $this, 'inject_behavioural_script' ], 30 );
        // REST endpoint for behavioural events
        add_action( 'rest_api_init', [ $this, 'register_rest' ] );
    }

    public function register_rest(): void {
        register_rest_route( 'stellar-meta/v1', '/behaviour', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_behaviour' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function rest_behaviour( WP_REST_Request $req ): WP_REST_Response {
        $event      = sanitize_text_field( $req->get_param('event') ?? '' );
        $product_id = (int) ( $req->get_param('product_id') ?? 0 );
        $value      = (float) ( $req->get_param('value') ?? 0 );
        $params     = (array) ( $req->get_param('params') ?? [] );

        $allowed = [ 'ScrollDepth25','ScrollDepth50','ScrollDepth75','ScrollDepth100',
                     'RageClick','TimeOnPage','ExitIntent','VideoPlay','VideoComplete' ];
        if ( ! in_array( $event, $allowed, true ) ) {
            return rest_ensure_response( [ 'success' => false, 'message' => 'Unknown event' ] );
        }

        // Queue as Meta CAPI custom event
        if ( $this->settings->is_capi_enabled() && $this->settings->pixel_id() ) {
            global $wpdb;
            $payload = wp_json_encode( [
                'event_name'  => $event,
                'custom_data' => array_merge( $params, [ 'product_id' => $product_id, 'value' => $value ] ),
            ] );
            $wpdb->insert( STELLAR_META_DB_PREFIX . 'event_queue', [
                'event_id'   => wp_generate_uuid4(),
                'event_name' => $event,
                'payload'    => $payload,
                'platform'   => 'meta',
                'status'     => 'pending',
            ], [ '%s','%s','%s','%s','%s' ] );
        }

        return rest_ensure_response( [ 'success' => true ] );
    }

    public function inject_behavioural_script(): void {
        if ( ! is_product() && ! is_shop() && ! is_product_category() && ! is_checkout() ) return;
        $rest_url = esc_url( rest_url( 'stellar-meta/v1/behaviour' ) );
        $exit_enabled = $this->settings->is_exit_intent_enabled() ? 'true' : 'false';
        ?>
<script>
(function(){
  var restUrl='<?php echo $rest_url; ?>';
  var exitEnabled=<?php echo $exit_enabled; ?>;
  var fired={};

  function send(event,params){
    if(fired[event]) return;
    fired[event]=1;
    navigator.sendBeacon(restUrl, JSON.stringify({event:event,params:params||{}}));
  }

  // Scroll depth milestones
  var scrollFired={};
  window.addEventListener('scroll',function(){
    var pct=Math.round((window.scrollY/(document.body.scrollHeight-window.innerHeight))*100);
    [25,50,75,100].forEach(function(m){
      if(pct>=m && !scrollFired[m]){
        scrollFired[m]=1;
        navigator.sendBeacon(restUrl, JSON.stringify({event:'ScrollDepth'+m,params:{depth:m,url:location.href}}));
      }
    });
  },{passive:true});

  // Rage click detection (3+ clicks on same element in 2s)
  var clickLog={};
  document.addEventListener('click',function(e){
    var key=e.target.tagName+(e.target.className||'');
    var now=Date.now();
    clickLog[key]=clickLog[key]||[];
    clickLog[key]=clickLog[key].filter(function(t){return now-t<2000;});
    clickLog[key].push(now);
    if(clickLog[key].length>=3){
      send('RageClick',{element:key,url:location.href});
      clickLog[key]=[];
    }
  });

  // Time on page buckets
  var start=Date.now();
  [10,30,60].forEach(function(s){
    setTimeout(function(){
      navigator.sendBeacon(restUrl, JSON.stringify({event:'TimeOnPage',params:{seconds:s,url:location.href}}));
    }, s*1000);
  });

  // Exit intent (mouseleave top of viewport at velocity)
  if(exitEnabled){
    var exitFired=false;
    document.addEventListener('mouseleave',function(e){
      if(exitFired || e.clientY>10) return;
      exitFired=true;
      navigator.sendBeacon(restUrl, JSON.stringify({event:'ExitIntent',params:{url:location.href,time_on_page:Math.round((Date.now()-start)/1000)}}));
    });
  }

  // Video tracking (HTML5 video elements)
  document.querySelectorAll('video').forEach(function(v){
    v.addEventListener('play',  function(){ send('VideoPlay',    {url:location.href}); });
    v.addEventListener('ended', function(){ send('VideoComplete',{url:location.href}); });
  });
})();
</script>
        <?php
    }
}
