<?php /* Included only by the administrator's authorized Activity Logs page. */ ?>
<section class="surface-card activity-card" id="activityFeed">
  <div class="section-heading"><div><span class="eyebrow">Recent activity</span><h2><?=intval($visibleCount)?> matching event<?=$visibleCount === 1 ? '' : 's'?></h2><p>Expand an event to see submitted data, its result, and recorded errors.</p></div></div>
  <div class="activity-feed view-list" id="activityFeedList">
  <?php if (!$rows->num_rows): ?><div class="empty-state"><h2>No activity found</h2><p>Change the user or date filters.</p></div><?php endif; ?>
  <?php while ($r = $rows->fetch_assoc()):
      $event = audit_decode_event($r['event_data'] ?? null);
      $outcome = $event['outcome'] ?? 'legacy';
      $hasErrors = !empty($event['errors']) || $outcome === 'error';
      $label = $hasErrors ? 'Error recorded' : ($outcome === 'success' ? 'Success' : ($event ? 'Recorded' : 'Earlier entry'));
  ?>
    <details class="audit-event">
      <summary>
        <span class="audit-expand-icon" aria-hidden="true"><?=ui_icon('chevron-right')?></span>
        <span class="audit-event-heading"><b><?=e($r['action'])?></b><small><?=e($r['full_name'] ?: 'System')?> · <?=e($r['role'] ? role_label($r['role']) : 'System')?> · <?=e($r['entity_type'] ?: 'General')?><?=$r['entity_id'] ? ' #' . intval($r['entity_id']) : ''?></small></span>
        <span class="badge text-bg-<?=$hasErrors ? 'danger' : ($outcome === 'success' ? 'success' : 'secondary')?>"><?=e($label)?></span>
        <time datetime="<?=e(date('c', strtotime($r['created_at'])))?>"><?=e(date('M d, Y h:i A', strtotime($r['created_at'])))?></time>
      </summary>
      <div class="audit-event-body">
        <?php if (!$event): ?>
          <p class="audit-legacy-note">This earlier entry has no structured transfer data. Submitted data and errors cannot be reconstructed.</p>
          <h3>Saved details</h3><pre><?=e($r['details'] ?: 'No additional details were saved.')?></pre>
        <?php else: ?>
          <p><?=e($r['details'] ?: 'Activity recorded.')?></p>
          <div class="audit-data-sections">
            <section><h3>Submitted data</h3><pre><?=e(audit_pretty_json($event['request'] ?? ['note'=>'Not recorded']))?></pre></section>
            <section><h3>Result</h3><pre><?=e(audit_pretty_json($event['response'] ?? $event['result'] ?? $event['messages'] ?? ['note'=>'Not recorded']))?></pre></section>
            <section class="<?=$hasErrors ? 'audit-error-section' : ''?>"><h3>Errors</h3>
              <?php if (!empty($event['errors'])): ?><pre><?=e(audit_pretty_json($event['errors']))?></pre><?php else: ?><p>No errors were recorded for this event.</p><?php endif; ?>
            </section>
            <?php if (!empty($event['transfers'])): ?><section><h3>Data transfers</h3><pre><?=e(audit_pretty_json($event['transfers']))?></pre></section><?php endif; ?>
            <?php foreach (['before'=>'Before the change','after'=>'After the change'] as $key=>$heading): if (array_key_exists($key, $event)): ?><section><h3><?=e($heading)?></h3><pre><?=e(audit_pretty_json($event[$key]))?></pre></section><?php endif; endforeach; ?>
          </div>
          <?php if (!empty($event['truncated'])): ?><p>Some data was omitted because it exceeded the audit size limit.</p><?php endif; ?>
          <details class="audit-raw"><summary>View stored JSON</summary><pre><?=e(audit_pretty_json($event))?></pre></details>
        <?php endif; ?>
      </div>
    </details>
  <?php endwhile; ?>
  </div>
  <?=render_pagination($page,$perPage,$visibleCount,['q'=>$q,'role'=>$roleFilter,'user_id'=>$userFilter,'entity'=>$entityFilter,'period'=>$period,'per_page'=>per_page_value($perPage),'_anchor'=>'activityFeed'])?>
</section>
