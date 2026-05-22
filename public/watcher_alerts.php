<?php
require_once '../config/db.php';
require_once '../scripts/get_watcher_alerts.php';

$status = $_GET['status'] ?? 'open';
if (!in_array($status, ['open', 'resolved', 'all'], true)) {
    $status = 'open';
}

$alerts = get_watcher_alerts($pdo, $status, 100);

function watcher_badge_class(string $severity): string
{
    return match ($severity) {
        'critical' => 'bg-danger',
        'warning'  => 'bg-warning text-dark',
        default    => 'bg-info text-dark',
    };
}

include '../layout/header.php';
?>

<h1 class="mb-4">👀 Watcher Alerts</h1>

<p class="text-muted">
    Watcher coverage now includes funding-health alerts, stale account imports, recurring-rule drift, likely missing recurring patterns,
    prediction miss accumulation, and unresolved review backlog.
</p>

<div class="mb-3 d-flex gap-2 flex-wrap">
    <a href="watcher_alerts.php?status=open" class="btn btn-sm <?= $status === 'open' ? 'btn-secondary' : 'btn-outline-secondary' ?>">Open</a>
    <a href="watcher_alerts.php?status=resolved" class="btn btn-sm <?= $status === 'resolved' ? 'btn-secondary' : 'btn-outline-secondary' ?>">Resolved</a>
    <a href="watcher_alerts.php?status=all" class="btn btn-sm <?= $status === 'all' ? 'btn-secondary' : 'btn-outline-secondary' ?>">All</a>
</div>

<?php if (empty($alerts)): ?>
    <p class="text-muted">No watcher alerts found for this filter.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th>Severity</th>
                    <th>Status</th>
                    <th>Type</th>
                    <th>Title</th>
                    <th>Related Account</th>
                    <th>Last Detected</th>
                    <th>Summary</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($alerts as $alert): ?>
                    <?php
                        $action = null;
                        if (!empty($alert['recommended_action_json'])) {
                            $decoded = json_decode((string)$alert['recommended_action_json'], true);
                            if (is_array($decoded)) {
                                $action = $decoded;
                            }
                        }

                        $evidence = null;
                        if (!empty($alert['evidence_json'])) {
                            $decodedEvidence = json_decode((string)$alert['evidence_json'], true);
                            if (is_array($decodedEvidence)) {
                                $evidence = $decodedEvidence;
                            }
                        }
                    ?>
                    <tr>
                        <td><span class="badge <?= watcher_badge_class((string)$alert['severity']) ?>"><?= htmlspecialchars((string)$alert['severity']) ?></span></td>
                        <td><?= htmlspecialchars((string)$alert['status']) ?></td>
                        <td><?= htmlspecialchars((string)$alert['alert_type']) ?></td>
                        <td><?= htmlspecialchars((string)$alert['title']) ?></td>
                        <td><?= htmlspecialchars((string)($alert['account_name'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)$alert['last_detected_at']) ?></td>
                        <td>
                            <div><?= htmlspecialchars((string)$alert['summary']) ?></div>
                            <?php if ($evidence): ?>
                                <details class="mt-2">
                                    <summary class="small text-muted">Evidence</summary>
                                    <pre class="small mb-0"><?= htmlspecialchars(json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                                </details>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (is_array($action) && !empty($action['url']) && !empty($action['label'])): ?>
                                <a href="<?= htmlspecialchars((string)$action['url']) ?>" class="btn btn-sm btn-outline-primary">
                                    <?= htmlspecialchars((string)$action['label']) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php include '../layout/footer.php'; ?>
