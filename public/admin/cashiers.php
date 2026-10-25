<?php
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_ADMIN);
$db = Database::getInstance();

// Create cashier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_cashier'])) {
  verifyCsrf();
  $name = sanitizeString($_POST['full_name'] ?? '');
  $user = sanitizeString($_POST['username'] ?? '');
  $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
  $pass = $_POST['password'] ?? '';
  $errors = [];
  if (empty($name)) $errors[] = 'Full name required.';
  if (empty($user)) $errors[] = 'Username required.';
  if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format.';
  $pwErr = validatePassword($pass);
  if ($pwErr) $errors[] = $pwErr;
  // Check username unique
  $chk = $db->prepare("SELECT id FROM cashiers WHERE username=? AND deleted_at IS NULL");
  $chk->execute([$user]);
  if ($chk->fetch()) $errors[] = 'Username already taken.';
  // Check email unique if provided
  if (!empty($email)) {
    $chk = $db->prepare("SELECT id FROM cashiers WHERE email=? AND deleted_at IS NULL");
    $chk->execute([$email]);
    if ($chk->fetch()) $errors[] = 'Email already in use.';
  }
  if (empty($errors)) {
    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare("INSERT INTO cashiers (full_name,username,email,password,created_by) VALUES (?,?,?,?,?)")
      ->execute([$name, $user, $email ?: null, $hash, currentUserId()]);
    auditLog(ROLE_ADMIN, currentUserId(), 'create_cashier');
    flash('global', 'Cashier account created.', 'success');
  } else {
    flash('global', implode(' ', $errors), 'error');
  }
  redirect(APP_URL . '/admin/cashiers.php');
}

// Toggle active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_cashier'])) {
  verifyCsrf();
  $id = filter_var($_POST['cashier_id'] ?? null, FILTER_VALIDATE_INT);
  if (!$id || $id < 1) {
    flash('global', 'Invalid cashier account.', 'error');
    redirect(APP_URL . '/admin/cashiers.php');
  }
  $stmt = $db->prepare("UPDATE cashiers SET is_active=NOT is_active WHERE id=? AND deleted_at IS NULL");
  $stmt->execute([$id]);
  flash('global', $stmt->rowCount() ? 'Cashier status updated.' : 'Cashier account was not found.', $stmt->rowCount() ? 'success' : 'error');
  redirect(APP_URL . '/admin/cashiers.php');
}

// A scalar lookup prevents duplicate admin IDs from multiplying cashier rows.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_cashier'])) {
  verifyCsrf();
  $id = filter_var($_POST['cashier_id'] ?? null, FILTER_VALIDATE_INT);
  if (!$id || $id < 1) {
    flash('global', 'Invalid cashier account.', 'error');
    redirect(APP_URL . '/admin/cashiers.php');
  }

  try {
    $db->beginTransaction();
    $stmt = $db->prepare('SELECT id, full_name, is_active, deleted_at FROM cashiers WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $cashier = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cashier || $cashier['deleted_at'] !== null) throw new RuntimeException('Cashier account was not found.');
    if ((int) $cashier['is_active'] === 1) throw new RuntimeException('Deactivate this cashier before deleting the account.');

    // Keep the row as an archived identity so existing orders and drawer records
    // retain their cashier name. Login credentials and contact details are removed.
    $deletedUsername = '__deleted_cashier_' . (int) $id . '_' . bin2hex(random_bytes(4));
    $unusablePassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $db->prepare('UPDATE cashiers SET deleted_at = NOW(), username = ?, email = NULL, email_verified = 0, email_verified_at = NULL, password = ?, pos_pin = NULL WHERE id = ? AND is_active = 0 AND deleted_at IS NULL')
      ->execute([$deletedUsername, $unusablePassword, $id]);
    if ($db->query('SELECT ROW_COUNT()')->fetchColumn() != 1) throw new RuntimeException('Cashier account changed before it could be deleted.');
    $db->commit();
    auditLog(ROLE_ADMIN, currentUserId(), 'delete_cashier', 'cashiers', (int) $id);
    flash('global', 'Cashier account deleted. Its sales and drawer history are retained for audit.', 'success');
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Admin cashier deletion failed: ' . $e->getMessage());
    flash('global', $e instanceof RuntimeException ? $e->getMessage() : 'Unable to delete this cashier account.', 'error');
  }
  redirect(APP_URL . '/admin/cashiers.php');
}

$cashiers = $db->query("SELECT c.*,
    (SELECT a.full_name FROM admins a WHERE a.id = c.created_by ORDER BY a.full_name LIMIT 1) AS created_by_name
  FROM cashiers c WHERE c.deleted_at IS NULL ORDER BY c.created_at DESC, c.id DESC")->fetchAll();
layoutHeader('Cashiers');
?>
<div class="page-header">
  <div>
    <div class="page-header-title">Cashiers</div>
    <div class="page-header-sub"><?= count($cashiers) ?> account<?= count($cashiers) !== 1 ? 's' : '' ?></div>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-primary" onclick="document.getElementById('cashier-modal').classList.remove('hidden')">
      <i class="fa-solid fa-user-plus"></i> Create Cashier
    </button>
  </div>
</div>
<?php showFlash('global'); ?>
<div class="card">
  <div style="overflow-x:auto">
    <table class="data-table">
      <thead>
        <tr>
          <th>Full Name</th>
          <th>Username</th>
          <th>Email</th>
          <th>Email Verified</th>
          <th>Status</th>
          <th>Created By</th>
          <th>Date Created</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($cashiers)): ?>
          <tr>
            <td colspan="8" style="text-align:center;padding:30px;color:var(--text-muted)">No cashier accounts yet.</td>
          </tr>
          <?php else: foreach ($cashiers as $c): ?>
            <tr>
              <td><strong><?= e($c['full_name']) ?></strong></td>
              <td><code><?= e($c['username']) ?></code></td>
              <td><?= e($c['email'] ?? '—') ?></td>
              <td>
                <?php if (!empty($c['email'])): ?>
                  <?php if (!empty($c['email_verified'])): ?>
                    <span class="badge badge-paid"><i class="fa-solid fa-check"></i> Verified</span>
                  <?php else: ?>
                    <span class="badge badge-pending"><i class="fa-solid fa-clock"></i> Pending</span>
                  <?php endif; ?>
                <?php else: ?>
                  <span style="color:var(--text-muted)">—</span>
                <?php endif; ?>
              </td>
              <td><span class="badge badge-<?= $c['is_active'] ? 'paid' : 'cancelled' ?>"><?= $c['is_active'] ? 'Active' : 'Inactive' ?></span></td>
              <td><?= e($c['created_by_name'] ?? '—') ?></td>
              <td><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
              <td>
                <form method="POST">
                  <?= csrfField() ?>
                  <input type="hidden" name="toggle_cashier" value="1">
                  <input type="hidden" name="cashier_id" value="<?= (int) $c['id'] ?>">
                  <button type="submit" class="btn <?= $c['is_active'] ? 'btn-danger' : 'btn-ghost' ?> btn-sm">
                    <i class="fa-solid <?= $c['is_active'] ? 'fa-ban' : 'fa-check' ?>"></i>
                    <?= $c['is_active'] ? 'Deactivate' : 'Activate' ?>
                  </button>
                </form>
                <?php if (!(int) $c['is_active']): ?>
                  <form method="POST" style="margin-top:6px" onsubmit="return confirm('Delete this cashier login? Past orders and drawer records will keep the cashier name for reporting.');">
                    <?= csrfField() ?>
                    <input type="hidden" name="delete_cashier" value="1">
                    <input type="hidden" name="cashier_id" value="<?= (int) $c['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm" aria-label="Delete <?= e($c['full_name']) ?>">
                      <i class="fa-solid fa-trash" aria-hidden="true"></i> Delete
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
        <?php endforeach;
        endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Create Cashier Modal -->
<div class="modal-overlay hidden" id="cashier-modal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><i class="fa-solid fa-user-plus" style="color:var(--primary-color);margin-right:8px"></i>Create Cashier Account</div>
      <button class="modal-close" onclick="document.getElementById('cashier-modal').classList.add('hidden')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="create_cashier" value="1">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Full Name</label><input type="text" name="full_name" class="form-control" required placeholder="Maria Santos"></div>
        <div class="form-group"><label class="form-label">Username</label><input type="text" name="username" class="form-control" required placeholder="cashier01"></div>
        <div class="form-group">
          <label class="form-label">Email Address (optional)</label>
          <input type="email" name="email" class="form-control" placeholder="cashier@example.com">
          <div class="form-hint">For password recovery and notifications</div>
        </div>
        <div class="form-group"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required minlength="8" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[\p{P}\p{S}]).{8,}" title="Use at least 8 characters with a lowercase letter, uppercase letter, number, and special character." placeholder="8+ chars, upper/lowercase, number, symbol"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('cashier-modal').classList.add('hidden')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Create Account</button>
      </div>
    </form>
  </div>
</div>
<?php layoutFooter(); ?>
