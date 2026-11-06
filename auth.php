<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

if (current_user()) redirect('index.php');

$mode = ($_GET['mode'] ?? $_POST['mode'] ?? 'login') === 'register' ? 'register' : 'login';
if ($mode === 'register' && empty($_SESSION['quiz_passed'])) redirect('gate.php');

$error = null;
$name = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = preg_replace('/\s+/u', ' ', post_str('name', 60));
    $pass = (string)($_POST['password'] ?? '');

    if ($mode === 'register') {
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

page_start($mode === 'register' ? 'Registrati' : 'Accedi', false);
?>
<section class="hero hero-small">
  <div class="wrap">
    <p class="kicker"><?= $mode === 'register' ? 'Quiz superato' : 'Bentornato/a' ?></p>
    <h1><?= $mode === 'register' ? 'Crea il tuo account' : 'Accedi' ?></h1>
  </div>
</section>
<section class="section">
  <div class="wrap narrow">
    <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="card form">
      <?= csrf_field() ?>
      <input type="hidden" name="mode" value="<?= $mode ?>">
      <label>Nome e cognome
        <input name="name" value="<?= e($name) ?>" required minlength="2" maxlength="60" autocomplete="username" autofocus>
      </label>
      <label>Password
        <input type="password" name="password" required minlength="4" autocomplete="<?= $mode === 'register' ? 'new-password' : 'current-password' ?>">
      </label>
      <button class="btn big"><?= $mode === 'register' ? 'Registrati' : 'Entra' ?></button>
      <p class="muted">
        <?php if ($mode === 'register'): ?>
          Hai già un account? <a href="auth.php?mode=login">Accedi</a>
        <?php else: ?>
          Non hai un account? <a href="gate.php">Rispondi alle domande e registrati</a>
        <?php endif; ?>
      </p>
    </form>
  </div>
</section>
<?php page_end();
