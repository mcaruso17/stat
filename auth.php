<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

if (current_user()) redirect('index.php');

$mode = $_GET['mode'] ?? $_POST['mode'] ?? 'login';
$mode = in_array($mode, ['register', 'forgot'], true) ? $mode : 'login';
$sent = false;
if ($mode === 'register' && empty($_SESSION['quiz_passed'])) redirect('gate.php');

$error = null;
$name = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = preg_replace('/\s+/u', ' ', post_str('name', 60));
    $pass = (string)($_POST['password'] ?? '');

    if ($mode === 'forgot') {
        // Richiesta all'admin: niente email, la nuova password arriva di persona.
        $_SESSION['pw_requests'] = ($_SESSION['pw_requests'] ?? 0) + 1;
        if (mb_strlen($name) < 2) {
            $error = 'Scrivi il nome con cui ti sei registrato.';
        } elseif ($_SESSION['pw_requests'] > 3) {
            $error = 'Hai già inviato diverse richieste: l\'amministratore ti contatterà.';
        } else {
            $st = db()->prepare('SELECT 1 FROM password_requests WHERE name = ? COLLATE NOCASE AND done_at IS NULL');
            $st->execute([$name]);
            if (!$st->fetchColumn()) {
                $st = db()->prepare('SELECT id FROM users WHERE name = ?');
                $st->execute([$name]);
                db()->prepare('INSERT INTO password_requests (user_id, name, contact, created_at) VALUES (?, ?, ?, ?)')
                    ->execute([$st->fetchColumn() ?: null, $name, post_str('contact', 120), time()]);
            }
            $sent = true;
        }
    } elseif ($mode === 'register') {
        if (mb_strlen($name) < 2) {
            $error = 'Il nome deve avere almeno 2 caratteri.';
        } elseif (mb_strlen($pass) < 4) {
            $error = 'La password deve avere almeno 4 caratteri.';
        } else {
            $st = db()->prepare('SELECT 1 FROM users WHERE name = ?');
            $st->execute([$name]);
            if ($st->fetchColumn()) {
                $error = 'Questo nome è già preso. Se sei tu, accedi.';
            } else {
                db()->prepare('INSERT INTO users (name, password_hash, created_at) VALUES (?, ?, ?)')
                    ->execute([$name, password_hash($pass, PASSWORD_DEFAULT), time()]);
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)db()->lastInsertId();
                flash('Account creato. Benvenuto/a in codice Stat!');
                redirect('index.php');
            }
        }
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE name = ?');
        $st->execute([$name]);
        $u = $st->fetch();
        if ($u && password_verify($pass, $u['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            redirect('index.php');
        }
        $error = 'Nome o password non corretti.';
    }
}

$titles = [
    'register' => ['Quiz superato', 'Crea il tuo account.', 'Nome e una password a tua scelta. Niente di complicato.', 'Registrati'],
    'login' => ['Bentornato/a', 'Accedi.', 'Con il nome e la password che hai scelto.', 'Entra'],
    'forgot' => ['Nessun problema', 'Password dimenticata.', 'Avvisiamo l\'amministratore: ti reimposta la password e te la comunica di persona.', 'Invia la richiesta'],
];
[$eyebrow, $title, $lead, $cta] = $titles[$mode];
page_start($mode === 'forgot' ? 'Password dimenticata' : ($mode === 'register' ? 'Registrati' : 'Accedi'), false);
?>
<section class="auth">
  <div class="wrap">
    <div class="tile auth-tile">
      <?php if ($sent): ?>
      <span class="auth-ok"><?= icon('check') ?></span>
      <h1>Richiesta inviata.</h1>
      <p class="muted">L'amministratore la vedrà appena entra nel sito e ti contatterà con una nuova password. Dopo, potrai accedere normalmente.</p>
      <p class="auth-foot"><a href="auth.php?mode=login">Torna all'accesso</a></p>
      <?php else: ?>
      <p class="eyebrow"><?= e($eyebrow) ?></p>
      <h1><?= e($title) ?></h1>
      <p class="muted"><?= e($lead) ?></p>
      <?php if ($error): ?><div class="alert" style="margin:18px 0 0"><?= icon('info') ?><span><?= e($error) ?></span></div><?php endif; ?>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="mode" value="<?= $mode ?>">
        <label>Nome e cognome
          <input name="name" value="<?= e($name) ?>" required minlength="2" maxlength="60" autocomplete="username" autofocus>
        </label>
        <?php if ($mode === 'forgot'): ?>
        <label>Come ti contattiamo? <small>Facoltativo. Telefono, email, oppure "ci vediamo in ufficio".</small>
          <input name="contact" maxlength="120">
        </label>
        <?php else: ?>
        <label>Password
          <input type="password" name="password" required minlength="4" autocomplete="<?= $mode === 'register' ? 'new-password' : 'current-password' ?>">
        </label>
        <?php endif; ?>
        <button class="btn big"><?= e($cta) ?></button>
      </form>
      <p class="auth-foot">
        <?php if ($mode === 'register'): ?>
          Hai già un account? <a href="auth.php?mode=login">Accedi</a>
        <?php elseif ($mode === 'forgot'): ?>
          Ti sei ricordato? <a href="auth.php?mode=login">Accedi</a>
        <?php else: ?>
          <a href="auth.php?mode=forgot">Password dimenticata?</a><br>
          Non hai un account? <a href="gate.php">Rispondi alle domande e registrati</a>
        <?php endif; ?>
      </p>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php page_end();
