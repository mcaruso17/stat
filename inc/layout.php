<?php
declare(strict_types=1);

const ICONS = [
    'pin' => '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
    'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    'arrow' => '<path d="M7 17L17 7M8.5 7H17v8.5"/>',
    'up' => '<path d="M6 15l6-6 6 6"/>',
    'down' => '<path d="M6 9l6 6 6-6"/>',
    'left' => '<path d="M15 6l-6 6 6 6"/>',
    'right' => '<path d="M9 6l6 6-6 6"/>',
    'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.6v.1"/>',
    'plus' => '<path d="M12 5v14M5 12h14"/>',
    'star' => '<path fill="currentColor" stroke="none" d="M12 3.2l2.7 5.5 6 .9-4.35 4.2 1.03 6L12 17l-5.38 2.8 1.03-6L3.3 9.6l6-.9z"/>',
    'chat' => '<path d="M20 12a8 8 0 0 1-11.6 7.1L4 20l1-4A8 8 0 1 1 20 12z"/>',
    'download' => '<path d="M12 4v11M7 10.5l5 5 5-5M5 20h14"/>',
    'upload' => '<path d="M12 16V5M7 9.5l5-5 5 5M5 20h14"/>',
    'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3 2"/>',
    'users' => '<circle cx="9" cy="8.5" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 5a3.5 3.5 0 0 1 0 7M21.5 20a6.5 6.5 0 0 0-3.8-5.9"/>',
    'close' => '<path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/>',
    'edit' => '<path d="M4 20h4.2L19.4 8.8a2 2 0 0 0 0-2.8l-1.4-1.4a2 2 0 0 0-2.8 0L4 15.8z"/>',
    'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
    'doc' => '<path d="M7 3h7l5 5v11a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M14 3v5h5"/>',
];

function icon(string $name, string $class = ''): string
{
    return '<svg class="ic' . ($class ? " $class" : '') . '" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor"'
        . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . (ICONS[$name] ?? '') . '</svg>';
}

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
<meta name="theme-color" content="#161617">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Inter+Tight:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=8">
<script>document.documentElement.classList.add('js')</script>
<link rel="icon" href="assets/logo.svg" type="image/svg+xml">
</head>
<body>
<header class="topbar">
  <div class="wrap topbar-inner">
    <a class="brand" href="<?= $u ? 'index.php' : 'gate.php' ?>">
      <img src="assets/logo.svg" alt="" width="22" height="22">
      <span>codice <b>stat</b></span>
    </a>
    <?php if ($nav && $u): ?>
    <nav class="mainnav">
      <a href="index.php#eventi">Eventi</a>
      <a href="index.php#discussione">Discussione</a>
      <a href="index.php#materiali">Materiali</a>
      <a href="index.php#membri">Membri</a>
      <?php if (!empty($u['is_admin'])): ?><a href="admin.php">Admin</a><?php endif; ?>
    </nav>
    <div class="userbox">
      <span class="avatar xs" style="background:<?= avatar_color($u['name']) ?>"><?= e(initials($u['name'])) ?></span>
      <span class="uname"><?= e($u['name']) ?></span>
      <a class="nav-out" href="logout.php">Esci</a>
    </div>
    <?php endif; ?>
  </div>
</header>
<?php if ($f): ?>
<div class="toast <?= e($f['type']) ?>" role="status"><?= icon($f['type'] === 'err' ? 'info' : 'check') ?><span><?= e($f['msg']) ?></span></div>
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
    <span class="brand dark"><img src="assets/logo.svg" alt="" width="18" height="18"><span>codice <b>stat</b></span></span>
    <span>Uno spazio informale tra colleghi statistici della Ragioneria. Correlazione non è causalità.</span>
  </div>
</footer>
<script src="assets/motion.js?v=2"></script>
<script src="assets/places.js?v=3"></script>
</body>
</html>
<?php
}

/** Intestazione di sezione centrata, in stile pagina prodotto. */
function section_head(string $eyebrow, string $title, string $sub = '', string $actions = ''): void
{
    ?>
    <header class="section-head">
      <p class="eyebrow"><?= e($eyebrow) ?></p>
      <h2><?= e($title) ?></h2>
      <?php if ($sub): ?><p class="section-sub"><?= e($sub) ?></p><?php endif; ?>
      <?php if ($actions): ?><div class="section-actions"><?= $actions ?></div><?php endif; ?>
    </header>
    <?php
}

/** Pulsante che apre un pannello <dialog>. */
function open_button(string $target, string $label, string $class = 'btn', string $ic = 'plus'): string
{
    return '<button type="button" class="' . e($class) . '" data-open="' . e($target) . '" aria-haspopup="dialog">'
        . ($ic ? icon($ic) : '') . '<span>' . e($label) . '</span></button>';
}

/** Apre un pannello modale; chiudere con sheet_end(). */
function sheet_start(string $id, string $title, string $eyebrow = ''): void
{
    ?>
<dialog class="sheet" id="<?= e($id) ?>" aria-labelledby="<?= e($id) ?>-t">
  <div class="sheet-head">
    <div>
      <?php if ($eyebrow): ?><p class="eyebrow"><?= e($eyebrow) ?></p><?php endif; ?>
      <h2 id="<?= e($id) ?>-t"><?= e($title) ?></h2>
    </div>
    <form method="dialog"><button class="icon-btn" aria-label="Chiudi"><?= icon('close') ?></button></form>
  </div>
<?php
}

function sheet_end(): void
{
    echo "</dialog>\n";
}

/**
 * Apertura a schermo intero: la frase entra parola per parola e,
 * scorrendo, si rimpicciolisce e sfuma (vedi assets/motion.js).
 */
function intro(string $kicker = '', string $extra = ''): void
{
    $i = 0;
    $words = function (string $text, string $class = '') use (&$i): string {
        $out = '';
        foreach (preg_split('/\s+/u', trim($text)) as $w) {
            $cls = 'w' . ($class ? " $class" : '');
            $out .= '<span class="' . $cls . '" style="--i:' . $i++ . '">' . e($w) . '</span> ';
        }
        return $out;
    };
    $title = $words('Ciao, questo è il sito dedicato al') . $words('codice Stat!', 'hl');
    $sub = $words('Qui possiamo organizzare') . $words('eventi,', 'c1') . $words('avere')
        . $words('discussioni,', 'c2') . $words('condividere') . $words('opinioni.', 'c3');
    ?>
<section class="intro" aria-label="Benvenuto">
  <div class="intro-sticky">
    <div class="orbs" aria-hidden="true"><span class="orb o1"></span><span class="orb o2"></span><span class="orb o3"></span></div>
    <div class="intro-inner">
      <?php if ($kicker): ?><p class="intro-kicker"><?= e($kicker) ?></p><?php endif; ?>
      <h1 class="intro-title"><?= $title ?></h1>
      <p class="intro-sub"><?= $sub ?></p>
      <?php if ($extra): ?><div class="intro-extra" style="--i:<?= $i ?>"><?= $extra ?></div><?php endif; ?>
    </div>
    <span class="scroll-cue" aria-hidden="true"><?= icon('down') ?></span>
  </div>
</section>
<?php
}

/** Frecce di voto per topic e commenti. */
function vote_box(string $type, int $id, int $score, int $mine, string $back): string
{
    $tok = csrf_field();
    $up = $mine === 1 ? ' on' : '';
    $down = $mine === -1 ? ' on' : '';
    $b = e($back);
    $iu = icon('up');
    $id_ = icon('down');
    return <<<HTML
<form class="votes" method="post" action="action.php">
  $tok
  <input type="hidden" name="action" value="vote">
  <input type="hidden" name="type" value="$type">
  <input type="hidden" name="id" value="$id">
  <input type="hidden" name="back" value="$b">
  <button class="vote up$up" name="value" value="1" aria-label="Voto positivo">$iu</button>
  <span class="score">$score</span>
  <button class="vote down$down" name="value" value="-1" aria-label="Voto negativo">$id_</button>
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
