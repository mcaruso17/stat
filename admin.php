<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$me = require_login();
if (!is_admin()) {
    flash('Area riservata agli amministratori.', 'err');
    redirect('index.php');
}

$users = db()->query('SELECT u.*,
    (SELECT COUNT(*) FROM topics t WHERE t.user_id = u.id) AS n_topics,
    (SELECT COUNT(*) FROM comments c WHERE c.user_id = u.id) AS n_comments,
    (SELECT COUNT(*) FROM documents d WHERE d.user_id = u.id) AS n_docs
    FROM users u ORDER BY u.created_at DESC')->fetchAll();

page_start('Amministrazione');
?>
<section class="section">
  <div class="wrap">
    <?php section_head('Amministrazione', 'Gestione utenti.', 'Reimposta le password dimenticate, nomina altri admin, elimina account. Puoi anche eliminare qualsiasi contenuto direttamente dalle pagine del sito.'); ?>
    <div class="panel table-scroll">
      <table class="admin-table">
        <thead><tr><th>Utente</th><th>Iscritto</th><th>Attività</th><th>Azioni</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): $self = (int)$u['id'] === (int)$me['id']; ?>
          <tr>
            <td><span class="admin-user"><span class="avatar xs" style="background:<?= avatar_color($u['name']) ?>"><?= e(initials($u['name'])) ?></span><?= e($u['name']) ?><?= $u['is_admin'] ? ' <span class="badge">Admin</span>' : '' ?></span></td>
            <td class="muted"><?= fmt_day((int)$u['created_at']) ?></td>
            <td class="muted"><?= (int)$u['n_topics'] ?> topic · <?= (int)$u['n_comments'] ?> commenti · <?= (int)$u['n_docs'] ?> file</td>
            <td>
              <div class="admin-actions">
                <form method="post" action="action.php" class="reset">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="admin_reset_password">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <input name="password" placeholder="Nuova password" minlength="4" required autocomplete="off">
                  <button class="btn sm secondary">Reimposta</button>
                </form>
                <?php if (!$self): ?>
                <form method="post" action="action.php" class="inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="admin_toggle_admin">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button class="linkbtn"><?= $u['is_admin'] ? 'Togli admin' : 'Rendi admin' ?></button>
                </form>
                <form method="post" action="action.php" class="inline" onsubmit="return confirm('Eliminare <?= e(addslashes($u['name'])) ?> e tutti i suoi contenuti?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="admin_delete_user">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button class="linkbtn danger">Elimina utente</button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php page_end();
