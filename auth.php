<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

/*
 * Accesso senza password: dopo il quiz si sceglie il proprio profilo da una griglia.
 * Solo gli account admin chiedono la password, perché possono eliminare utenti e contenuti.
 */

if (current_user()) redirect('index.php');
if (!browser_known()) redirect('gate.php');

$error = null;
$name = '';
$askPassword = null; // admin scelto: serve la password

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['do'] ?? '';

    if ($action === 'pick') {
        $st = db()->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([(int)($_POST['user_id'] ?? 0)]);
        $u = $st->fetch();
        if (!$u) {
            $error = 'Profilo non trovato.';
        } elseif (!$u['is_admin']) {
            login_success((int)$u['id']);
        } elseif (isset($_POST['password'])) {
            if (password_verify((string)$_POST['password'], $u['password_hash'])) login_success((int)$u['id']);
            $askPassword = $u;
            $error = 'Password non corretta.';
        } else {
            $askPassword = $u;
        }
    } elseif ($action === 'create') {
        $name = preg_replace('/\s+/u', ' ', post_str('name', 60));
        if ($err = name_error($name)) {
            $error = $err . ($err[0] === 'E' ? ' Sceglilo dalla lista qui sopra.' : '');
        } else {
            flash('Profilo creato. Benvenuto/a in codice Stat!');
            login_success(create_user($name));
        }
    }
}

$users = db()->query('SELECT id, name, is_admin, zone FROM users ORDER BY name COLLATE NOCASE')->fetchAll();

page_start($askPassword ? 'Accesso admin' : 'Chi sei?', false);
?>
<section class="auth">
  <div class="wrap">
    <?php if ($askPassword): ?>
    <div class="tile auth-tile">
      <span class="avatar xl" style="background:<?= avatar_color($askPassword['name']) ?>"><?= e(initials($askPassword['name'])) ?></span>
      <p class="eyebrow" style="margin-top:16px">Account admin</p>
      <h1><?= e($askPassword['name']) ?></h1>
      <p class="muted">Questo profilo può gestire utenti e contenuti, quindi serve la password.</p>
      <?php if ($error): ?><div class="alert" style="margin:18px 0 0"><?= icon('info') ?><span><?= e($error) ?></span></div><?php endif; ?>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="pick">
        <input type="hidden" name="user_id" value="<?= (int)$askPassword['id'] ?>">
        <label>Password <input type="password" name="password" required autocomplete="current-password" autofocus></label>
        <button class="btn big">Entra</button>
      </form>
      <p class="auth-foot"><a href="auth.php">Non sei tu? Torna ai profili</a></p>
    </div>
    <?php else: ?>
    <div class="picker">
      <?php section_head('Accesso', 'Chi sei?', 'Tocca il tuo profilo per entrare.'); ?>
      <?php if ($error): ?><div class="alert"><?= icon('info') ?><span><?= e($error) ?></span></div><?php endif; ?>
      <?php if (count($users) > 8): ?>
      <div class="picker-search"><input type="search" placeholder="Cerca il tuo nome" aria-label="Cerca il tuo nome" data-picker-search></div>
      <?php endif; ?>
      <form method="post" class="picker-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="pick">
        <?php foreach ($users as $u): ?>
        <button class="tile picker-card hoverable" name="user_id" value="<?= (int)$u['id'] ?>" data-name="<?= e(mb_strtolower($u['name'])) ?>">
          <span class="avatar lg" style="background:<?= avatar_color($u['name']) ?>"><?= e(initials($u['name'])) ?></span>
          <span class="picker-name"><?= e($u['name']) ?></span>
          <?php if ($u['is_admin']): ?><span class="picker-lock"><?= icon('lock') ?>Admin, con password</span>
          <?php elseif ($u['zone']): ?><span class="picker-sub"><?= e($u['zone']) ?></span><?php endif; ?>
        </button>
        <?php endforeach; ?>
      </form>

      <div class="tile picker-new">
        <div>
          <h3>Non sei nella lista?</h3>
          <p class="muted small">Crea il tuo profilo: basta il nome.</p>
        </div>
        <form method="post" class="picker-new-form">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="create">
          <input name="name" value="<?= e($name) ?>" required minlength="2" maxlength="60" placeholder="Nome e cognome" aria-label="Nome e cognome">
          <button class="btn"><?= icon('plus') ?><span>Crea profilo</span></button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>
<script>
document.querySelector('[data-picker-search]')?.addEventListener('input', e => {
  const q = e.target.value.trim().toLowerCase();
  document.querySelectorAll('.picker-card').forEach(c => { c.hidden = q && !c.dataset.name.includes(q); });
});
</script>
<?php page_end();
