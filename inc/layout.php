<?php
declare(strict_types=1);

function page_start(string $title, bool $nav = true): void
{
    $u = current_user();
    $f = flash();
    ?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;700;900&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=1">
<link rel="icon" href="assets/logo.svg" type="image/svg+xml">
</head>
<body>
<header class="topbar">
  <div class="wrap topbar-inner">
    <a class="brand" href="<?= $u ? 'index.php' : 'gate.php' ?>">
      <img src="assets/logo.svg" alt="" width="34" height="34">
      <span>codice<b>stat</b></span>
    </a>
    <?php if ($nav && $u): ?>
    <nav class="mainnav">
      <a href="index.php#eventi">Eventi</a>
      <a href="index.php#discussione">Discussione</a>
      <a href="index.php#materiali">Materiali</a>
      <a href="index.php#membri">Membri</a>
    </nav>
    <div class="userbox">
      <span class="avatar sm" style="background:<?= avatar_color($u['name']) ?>"><?= e(initials($u['name'])) ?></span>
      <span class="uname"><?= e($u['name']) ?></span>
      <a class="btn ghost sm" href="logout.php">Esci</a>
    </div>
    <?php endif; ?>
  </div>
</header>
<?php if ($f): ?>
<div class="flash <?= e($f['type']) ?>"><div class="wrap"><?= e($f['msg']) ?></div></div>
<?php endif; ?>
<main>
<?php
}

function page_end(): void
{
    ?>
</main>
<footer class="footer">
  <div class="wrap footer-inner">
    <span class="brand small"><img src="assets/logo.svg" alt="" width="24" height="24"><span>codice<b>stat</b></span></span>
    <span>Uno spazio informale tra colleghi statistici della Ragioneria. Correlazione non è causalità.</span>
  </div>
</footer>
</body>
</html>
<?php
}

/** Frecce di voto stile Reddit. */
function vote_box(string $type, int $id, int $score, int $mine, string $back): string
{
    $tok = csrf_field();
    $up = $mine === 1 ? ' on' : '';
    $down = $mine === -1 ? ' on' : '';
    $b = e($back);
    return <<<HTML
<form class="votes" method="post" action="action.php">
  $tok
  <input type="hidden" name="action" value="vote">
  <input type="hidden" name="type" value="$type">
  <input type="hidden" name="id" value="$id">
  <input type="hidden" name="back" value="$b">
  <button class="vote up$up" name="value" value="1" aria-label="Voto positivo">▲</button>
  <span class="score">$score</span>
  <button class="vote down$down" name="value" value="-1" aria-label="Voto negativo">▼</button>
</form>
HTML;
}

function delete_button(string $action, int $id, string $back, string $label = 'Elimina'): string
{
    $tok = csrf_field();
    $b = e($back);
    $l = e($label);
    return <<<HTML
<form class="inline" method="post" action="action.php" onsubmit="return confirm('Sicuro di voler eliminare?')">
  $tok
  <input type="hidden" name="action" value="$action">
  <input type="hidden" name="id" value="$id">
  <input type="hidden" name="back" value="$b">
  <button class="linkbtn danger">$l</button>
</form>
HTML;
}
