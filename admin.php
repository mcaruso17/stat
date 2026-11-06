<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$me = require_login();
if (!is_admin()) {
    flash('Area riservata agli amministratori.', 'err');
    redirect('index.php');
}

$cats = db()->query("SELECT c.*, (SELECT COUNT(*) FROM topics t WHERE t.category = c.name) AS n_topics
    FROM categories c ORDER BY c.name = '" . FALLBACK_CATEGORY . "', c.id")->fetchAll();

function color_select(string $current): string
{
    $out = '<select name="color" aria-label="Colore">';
    foreach (CATEGORY_COLORS as $cls => $label) {
        $out .= '<option value="' . e($cls) . '"' . ($cls === $current ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $out . '</select>';
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
    <h3 class="admin-sub">Utenti</h3>
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

    <h3 class="admin-sub" id="categorie">Categorie della discussione</h3>
    <p class="muted small">Rinomina o cambia colore: i topic esistenti seguono automaticamente. Se elimini una categoria, i suoi topic passano in "<?= e(FALLBACK_CATEGORY) ?>", che non si può eliminare.</p>
    <div class="panel table-scroll">
      <table class="admin-table cats-table">
        <thead><tr><th>Categoria</th><th>Topic</th><th>Modifica</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($cats as $c): $fallback = $c['name'] === FALLBACK_CATEGORY; ?>
          <tr>
            <td><span class="cat <?= e($c['color']) ?>"><span class="cat-dot"></span><?= e($c['name']) ?></span></td>
            <td class="muted"><?= (int)$c['n_topics'] ?></td>
            <td>
              <form method="post" action="action.php" class="cat-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="admin_edit_category">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <input name="name" value="<?= e($c['name']) ?>" maxlength="40" required <?= $fallback ? 'readonly title="Questa categoria non si può rinominare"' : '' ?>>
                <?= color_select($c['color']) ?>
                <button class="btn sm secondary">Salva</button>
              </form>
            </td>
            <td><?php if (!$fallback): ?>
              <form method="post" action="action.php" class="inline" onsubmit="return confirm('Eliminare la categoria? I suoi <?= (int)$c['n_topics'] ?> topic passeranno in <?= e(FALLBACK_CATEGORY) ?>.')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="admin_delete_category">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="linkbtn danger">Elimina</button>
              </form>
            <?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
          <tr class="add-row">
            <td colspan="4">
              <form method="post" action="action.php" class="cat-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="admin_add_category">
                <input name="name" maxlength="40" required placeholder="Nuova categoria, es. Smart working">
                <?= color_select('c-sky') ?>
                <button class="btn sm"><?= icon('plus') ?><span>Aggiungi</span></button>
              </form>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php page_end();
