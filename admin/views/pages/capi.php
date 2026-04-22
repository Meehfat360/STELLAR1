<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to view this page.', 'stellar-meta' ) ); }
$queue  = Stellar_Meta_Event_Queue::stats();
$events = Stellar_Meta_Event_Queue::recent_events( 50 );
?>
<div class="sm-header">
  <div class="sm-header-left">
    <div class="sm-logo">S</div>
    <div>
      <div class="sm-header-title">CAPI Engine</div>
      <div class="sm-header-sub">Conversions API · Queue · Retry · Debugger</div>
    </div>
  </div>
  <div class="sm-header-right">
    <span class="sm-badge sm-badge-green"><span class="sm-dot"></span> Queue Active</span>
    <span class="sm-badge sm-badge-muted">Next run: ~1 min</span>
  </div>
</div>

<div class="sm-content">

  <!-- Stats Row -->
  <div class="sm-metrics">
    <div class="sm-metric accent-green">
      <div class="sm-metric-label">Delivered (24h)</div>
      <div class="sm-metric-value" style="color:var(--sm-green)"><?php echo esc_html( number_format($queue['delivered']) ); ?></div>
      <div class="sm-metric-delta flat">● events sent</div>
    </div>
    <div class="sm-metric">
      <div class="sm-metric-label">Retrying</div>
      <div class="sm-metric-value" style="color:var(--sm-amber)"><?php echo esc_html( $queue['retrying'] ); ?></div>
      <div class="sm-metric-delta flat">● pending retry</div>
    </div>
    <div class="sm-metric">
      <div class="sm-metric-label">Failed</div>
      <div class="sm-metric-value" style="color:var(--sm-red)"><?php echo esc_html( $queue['failed'] ); ?></div>
      <div class="sm-metric-delta flat">● max retries hit</div>
    </div>
    <div class="sm-metric accent-blue">
      <div class="sm-metric-label">Avg Latency</div>
      <div class="sm-metric-value"><?php echo esc_html( $queue['avg_latency'] ); ?><span style="font-size:14px;font-weight:400">ms</span></div>
      <div class="sm-metric-delta flat">● per event</div>
    </div>
  </div>

  <!-- Engine Config -->
  <div class="sm-grid-2" style="margin-bottom:18px">
    <div class="sm-card">
      <div class="sm-card-header"><span class="sm-card-title">Queue Engine Config</span></div>
      <div class="sm-card-body">
        <?php
        $s = Stellar_Meta_Settings::instance();
        $configs = [
          [ 'Dispatcher',       'WordPress Action Scheduler + WP-Cron' ],
          [ 'Retry Policy',     $s->capi_max_retries() . ' attempts · ' . $s->capi_retry_backoff() . 's base backoff (×3 exp)' ],
          [ 'Dedup Window',     $s->dedup_window_hours() . 'h · SHA-256 event_id hash' ],
          [ 'Log Retention',    $s->capi_log_retention_days() . ' days' ],
          [ 'PII Hashing',      $s->is_pii_hashing() ? 'SHA-256 (em, ph, fn, ln, ct, zp)' : 'Disabled' ],
          [ 'Batch Size',       '50 events per cron run' ],
        ];
        foreach ( $configs as [ $k, $v ] ) : ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid rgba(46,46,56,.5)">
          <span style="font-size:11px;color:var(--sm-muted);font-family:'DM Mono',monospace;text-transform:uppercase;letter-spacing:.07em"><?php echo esc_html($k); ?></span>
          <span style="font-size:12px;font-family:'DM Mono',monospace;color:var(--sm-text)"><?php echo esc_html($v); ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="sm-card">
      <div class="sm-card-header"><span class="sm-card-title">Queue Depth</span></div>
      <div class="sm-card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <?php foreach ( [
            [ 'In Queue',   $queue['queue_depth'], 'var(--sm-blue)'  ],
            [ 'Pending',    $queue['pending'],     'var(--sm-muted)' ],
            [ 'Retrying',   $queue['retrying'],    'var(--sm-amber)' ],
            [ 'Queue Avg',  $queue['avg_latency'].'ms', 'var(--sm-text)' ],
          ] as [ $l, $v, $c ] ) : ?>
          <div style="background:var(--sm-raised);border-radius:9px;padding:14px;border:1px solid var(--sm-border)">
            <div style="font-size:22px;font-weight:700;font-family:'DM Mono',monospace;color:<?php echo esc_attr($c); ?>"><?php echo esc_html($v); ?></div>
            <div style="font-size:9px;color:var(--sm-muted);text-transform:uppercase;letter-spacing:.1em;font-family:'DM Mono',monospace;margin-top:4px"><?php echo esc_html($l); ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Event Log -->
  <div class="sm-card">
    <div class="sm-card-header">
      <span class="sm-card-title">Event Queue Log</span>
      <div style="display:flex;gap:8px;align-items:center">
        <input type="text" id="sm-event-filter" class="sm-input" style="width:200px;padding:6px 12px;font-size:12px" placeholder="Filter by event name…">
        <span class="sm-badge sm-badge-muted"><?php echo esc_html( count($events) ); ?> events</span>
      </div>
    </div>
    <div class="sm-table-wrap">
      <table class="sm-table" id="sm-events-table">
        <thead>
          <tr>
            <th>Event</th>
            <th>Status</th>
            <th>Attempts</th>
            <th>Latency</th>
            <th>Error</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ( $events as $e ) :
            $status_class = match( $e['status'] ) {
              'delivered'  => 'sm-status-ok',
              'failed'     => 'sm-status-fail',
              'retrying'   => 'sm-status-retry',
              default      => 'sm-status-pending',
            };
          ?>
          <tr>
            <td class="sm-mono"><?php echo esc_html( $e['event_name'] ); ?></td>
            <td><span class="sm-status <?php echo esc_attr($status_class); ?>"><?php echo esc_html( $e['status'] ); ?></span></td>
            <td class="sm-mono" style="color:var(--sm-muted)"><?php echo esc_html( $e['attempts'] ); ?></td>
            <td class="sm-mono" style="color:var(--sm-muted)"><?php echo $e['latency_ms'] ? esc_html($e['latency_ms']).'ms' : '—'; ?></td>
            <td style="font-size:11px;color:var(--sm-red);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo esc_html( $e['error_message'] ?? '' ); ?></td>
            <td class="sm-mono" style="color:var(--sm-muted);font-size:11px"><?php echo esc_html( $e['created_at'] ); ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if ( empty($events) ) : ?>
          <tr><td colspan="6"><div class="sm-empty"><div class="sm-empty-icon">📭</div><p>No events in queue yet. Events will appear here once your store receives traffic.</p></div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script>
jQuery(function($){
  $('#sm-event-filter').on('input', function(){
    var q = this.value.toLowerCase();
    $('#sm-events-table tbody tr').each(function(){
      $(this).toggle(!q || $(this).find('td:first').text().toLowerCase().includes(q));
    });
  });
});
</script>
