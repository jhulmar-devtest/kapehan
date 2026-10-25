<?php
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_ADMIN);
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verifyCsrf();
  $reportId = (int) ($_POST['report_id'] ?? 0);
  $action = $_POST['action'] ?? '';
  $note = sanitizeString($_POST['resolution_note'] ?? '', 500);

  if (!in_array($action, ['resolve', 'dismiss'], true)) {
    flash('global', 'Choose a valid review action.', 'error');
  } elseif ($action === 'resolve' && (($_POST['identity_checked'] ?? '') !== '1' || $note === '')) {
    flash('global', 'Verify the claimant through the school and record how you checked before releasing the ID.', 'error');
  } elseif ($action === 'dismiss' && $note === '') {
    flash('global', 'Enter a short reason before dismissing the report.', 'error');
  } else {
    try {
      $db->beginTransaction();
      $reportStmt = $db->prepare("SELECT * FROM registration_conflict_reports WHERE id = ? FOR UPDATE");
      $reportStmt->execute([$reportId]);
      $report = $reportStmt->fetch(PDO::FETCH_ASSOC);
      if (!$report || $report['status'] !== 'pending') throw new RuntimeException('This report is no longer pending review.');

      $accountId = null;
      if ($action === 'resolve') {
        $accountConfig = $report['user_type'] === ROLE_STUDENT
          ? ['table' => 'students', 'identifier' => 'student_id_no']
          : ['table' => 'faculty', 'identifier' => 'faculty_id_no'];
        $accountStmt = $db->prepare("SELECT id FROM {$accountConfig['table']} WHERE {$accountConfig['identifier']} = ? LIMIT 1 FOR UPDATE");
        $accountStmt->execute([$report['school_id_no']]);
        $accountId = $accountStmt->fetchColumn();

        if ($accountId !== false) {
          $accountId = (int) $accountId;
          $releaseToken = strtoupper(bin2hex(random_bytes(4)));
          $releasedId = 'REVOKED-' . strtoupper(substr($report['user_type'], 0, 1)) . $accountId . '-' . $releaseToken;
          $releasedEmail = 'revoked-' . $report['user_type'] . '-' . $accountId . '-' . strtolower($releaseToken) . '@invalid.local';
          $db->prepare(
            "UPDATE {$accountConfig['table']}
             SET {$accountConfig['identifier']} = ?, email = ?, password = ?, full_name = 'Deactivated account',
                 email_verified = 0, is_active = 0, registration_expires_at = NULL
             WHERE id = ?"
          )->execute([$releasedId, $releasedEmail, '!revoked!' . $releaseToken, $accountId]);
          $db->prepare('DELETE FROM email_otps WHERE user_type = ? AND user_id = ?')->execute([$report['user_type'], $accountId]);
          auditLog(ROLE_ADMIN, (int) currentUserId(), 'release_claimed_school_id', $accountConfig['table'], $accountId);
        }
        $newStatus = 'resolved';
      } else {
        $newStatus = 'dismissed';
      }

      $db->prepare(
        'UPDATE registration_conflict_reports SET status = ?, account_id = ?, reviewed_by = ?, reviewed_at = NOW(), resolution_note = ? WHERE id = ?'
      )->execute([$newStatus, $accountId, currentUserId(), $note, $reportId]);
      $db->commit();
      flash('global', $action === 'resolve'
        ? 'Report resolved. The previous account was deactivated and its ID released, if it was still present.'
        : 'Report dismissed.', 'success');
    } catch (Throwable $e) {
      if ($db->inTransaction()) $db->rollBack();
      error_log('ID conflict review failed: ' . $e->getMessage());
      flash('global', $e instanceof RuntimeException ? $e->getMessage() : 'Unable to save the review. Please try again.', 'error');
    }
  }
  redirect(APP_URL . '/admin/id-conflicts.php');
}

$reports = $db->query(
  "SELECT r.*,
          COALESCE(s.id, f.id) AS matched_account_id,
          COALESCE(s.full_name, f.full_name) AS matched_account_name,
          COALESCE(s.email, f.email) AS matched_account_email,
          COALESCE(s.email_verified, f.email_verified) AS matched_account_verified,
          COALESCE(s.is_active, f.is_active) AS matched_account_active
   FROM registration_conflict_reports r
   LEFT JOIN students s ON r.user_type = 'student' AND s.student_id_no = r.school_id_no
   LEFT JOIN faculty f ON r.user_type = 'faculty' AND f.faculty_id_no = r.school_id_no
   ORDER BY (r.status = 'pending') DESC, r.created_at DESC LIMIT 250"
)->fetchAll(PDO::FETCH_ASSOC);
$pendingCount = count(array_filter($reports, static fn($report) => $report['status'] === 'pending'));

layoutHeader('ID Conflict Reports');
?>
<div class="page-header">
  <div>
    <div class="page-header-title">ID Conflict Reports</div>
    <div class="page-header-sub">Review claims that an existing account is using someone else’s school ID.</div>
  </div>
  <div class="page-header-actions"><span class="badge badge-<?= $pendingCount > 0 ? 'pending' : 'paid' ?>"><?= $pendingCount ?> pending</span></div>
</div>

<?php showFlash('global'); ?>

<div class="card">
  <div class="card-header">
    <div>
      <div class="card-title">Manual review</div>
      <div class="text-muted" style="font-size:.82rem;margin-top:4px">Contact the claimant and verify their identity through the school before releasing an ID. Resolving deactivates the account currently using that ID and keeps its order history intact.</div>
    </div>
  </div>
  <div style="overflow-x:auto">
    <table class="data-table">
      <thead>
        <tr>
          <th>Report</th>
          <th>Claimant</th>
          <th>Existing account</th>
          <th>Details</th>
          <th>Status / action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$reports): ?>
          <tr>
            <td colspan="5" style="text-align:center;padding:32px;color:var(--text-muted)">No ID conflict reports have been submitted.</td>
          </tr>
          <?php else: foreach ($reports as $report): ?>
            <tr>
              <td><strong>#<?= (int) $report['id'] ?></strong><br><span class="text-muted"><?= e(date('M j, Y g:i A', strtotime($report['created_at']))) ?></span></td>
              <td><strong><?= e($report['full_name']) ?></strong><br><?= e($report['user_type'] === ROLE_STUDENT ? 'Student' : 'Faculty') ?> · <?= e($report['school_id_no']) ?><br><a href="mailto:<?= e($report['contact_email']) ?>"><?= e($report['contact_email']) ?></a></td>
              <td><?php if ($report['matched_account_id']): ?><strong><?= e($report['matched_account_name']) ?></strong><br><?= e($report['matched_account_email']) ?><br><span class="text-muted"><?= !empty($report['matched_account_verified']) ? 'Email verified' : 'Email not verified' ?><?= empty($report['matched_account_active']) ? ' · inactive' : '' ?></span><?php else: ?><span class="text-muted">No matching account now</span><?php endif; ?></td>
              <td style="white-space:normal;min-width:200px;max-width:340px"><?= $report['details'] !== null && $report['details'] !== '' ? nl2br(e($report['details'])) : '<span class="text-muted">—</span>' ?><?php if ($report['resolution_note']): ?><div style="margin-top:8px;font-size:.8rem"><strong>Review:</strong> <?= e($report['resolution_note']) ?></div><?php endif; ?></td>
              <td>
                <?php if ($report['status'] === 'pending'): ?>
                  <form method="POST" style="min-width:230px;display:grid;gap:8px">
                    <?= csrfField() ?><input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
                    <label style="font-size:.78rem;display:flex;gap:7px;align-items:flex-start"><input type="checkbox" name="identity_checked" value="1" required style="margin-top:3px"> I verified the claimant through the school.</label>
                    <textarea class="form-control" name="resolution_note" maxlength="500" rows="2" placeholder="How was identity verified? Required to release the ID." required></textarea>
                    <div style="display:flex;gap:7px;flex-wrap:wrap"><button class="btn btn-sm btn-primary" name="action" value="resolve" type="submit"><i class="fa-solid fa-unlock-keyhole"></i> Verify &amp; release ID</button><button class="btn btn-sm btn-ghost" name="action" value="dismiss" type="submit" onclick="this.form.identity_checked.required=false;this.form.resolution_note.required=true">Dismiss</button></div>
                  </form>
                <?php else: ?>
                  <span class="badge badge-<?= $report['status'] === 'resolved' ? 'paid' : 'cancelled' ?>"><?= e(ucfirst($report['status'])) ?></span><?php if ($report['reviewed_at']): ?><br><span class="text-muted"><?= e(date('M j, Y', strtotime($report['reviewed_at']))) ?></span><?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
        <?php endforeach;
        endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layoutFooter(); ?>