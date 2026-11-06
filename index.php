<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$me = require_login();
$uid = (int)$me['id'];
$pdo = db();
$today = date('Y-m-d');

// Eventi
$evSql = 'SELECT e.*, u.name AS author,
    (SELECT COUNT(*) FROM event_rsvp r WHERE r.event_id = e.id) AS n_going,
    (SELECT COUNT(*) FROM event_rsvp r WHERE r.event_id = e.id AND r.user_id = :uid) AS going,
    (SELECT GROUP_CONCAT(u2.name, \', \') FROM event_rsvp r JOIN users u2 ON u2.id = r.user_id WHERE r.event_id = e.id) AS going_names
    FROM events e LEFT JOIN users u ON u.id = e.user_id';
$st = $pdo->prepare("$evSql WHERE e.event_date >= :d ORDER BY e.event_date, e.event_time");
$st->execute([':uid' => $uid, ':d' => $today]);
$upcoming = $st->fetchAll();
$st = $pdo->prepare("$evSql WHERE e.event_date < :d ORDER BY e.event_date DESC LIMIT 12");
$st->execute([':uid' => $uid, ':d' => $today]);
$past = $st->fetchAll();

// Discussione
$sort = ($_GET['sort'] ?? 'new') === 'top' ? 'top' : 'new';
$cat = in_array($_GET['cat'] ?? '', TOPIC_CATEGORIES, true) ? $_GET['cat'] : '';
$where = $cat ? 'WHERE t.category = :cat' : '';
$order = $sort === 'top' ? 'score DESC, t.created_at DESC' : 't.created_at DESC';
$st = $pdo->prepare("SELECT t.*, u.name AS author,
    COALESCE((SELECT SUM(value) FROM votes v WHERE v.target_type = 'topic' AND v.target_id = t.id), 0) AS score,
    COALESCE((SELECT value FROM votes v WHERE v.target_type = 'topic' AND v.target_id = t.id AND v.user_id = :uid), 0) AS mine,
    (SELECT COUNT(*) FROM comments c WHERE c.topic_id = t.id) AS n_comments
    FROM topics t JOIN users u ON u.id = t.user_id $where ORDER BY $order LIMIT 100");
$params = [':uid' => $uid];
if ($cat) $params[':cat'] = $cat;
$st->execute($params);
$topics = $st->fetchAll();

// Materiali
$docs = $pdo->query('SELECT d.*, u.name AS author FROM documents d JOIN users u ON u.id = d.user_id ORDER BY d.created_at DESC')->fetchAll();

// Membri
$members = $pdo->query('SELECT u.*,
    (SELECT COUNT(*) FROM topics t WHERE t.user_id = u.id) AS n_topics,
    (SELECT COUNT(*) FROM comments c WHERE c.user_id = u.id) AS n_comments,
    (SELECT COUNT(*) FROM documents d WHERE d.user_id = u.id) AS n_docs
    FROM users u ORDER BY u.name COLLATE NOCASE')->fetchAll();

function event_card(array $ev, int $uid, bool $isPast = false): void
{
    $ts = strtotime($ev['event_date']);
    ?>
    <article class="card event<?= $isPast ? ' past' : '' ?>">
      <div class="datebox">
        <span class="d"><?= date('j', $ts) ?></span>
        <span class="m"><?= MONTHS[(int)date('n', $ts) - 1] ?></span>
        <span class="y"><?= date('Y', $ts) ?></span>
      </div>
      <div class="event-body">
        <p class="label"><?= e(WEEKDAYS[(int)date('w', $ts)]) ?><?= $ev['event_time'] ? ' · ore ' . e($ev['event_time']) : '' ?><?= $ev['location'] ? ' · ' . e($ev['location']) : '' ?></p>
        <h3><?= e($ev['title']) ?></h3>
        <?php if ($ev['description']): ?><p><?= rich($ev['description']) ?></p><?php endif; ?>
        <div class="event-foot">
          <?php if (!$isPast): ?>
          <form method="post" action="action.php" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="rsvp">
            <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
            <button class="btn sm <?= $ev['going'] ? 'on' : 'ghost' ?>"><?= $ev['going'] ? '✓ Ci sono' : 'Partecipo' ?></button>
          </form>
          <?php endif; ?>
          <span class="muted" title="<?= e($ev['going_names'] ?? '') ?>"><?= (int)$ev['n_going'] ?> partecipant<?= (int)$ev['n_going'] === 1 ? 'e' : 'i' ?><?= $ev['going_names'] ? ': ' . e($ev['going_names']) : '' ?></span>
          <?php if (can_manage($ev['user_id'])): ?><?= delete_button('delete_event', (int)$ev['id'], 'index.php#eventi') ?><?php endif; ?>
        </div>
      </div>
    </article>
    <?php
}

page_start('Home');
?>
<?php intro(
    'Bentornato/a, ' . explode(' ', $me['name'])[0],
    '<a class="btn" href="#discussione">Apri un topic</a> <a class="btn ghost light" href="#eventi">Prossimi eventi</a>'
); ?>

<!-- EVENTI -->
<section class="section" id="eventi">
  <div class="wrap">
    <div class="section-head">
      <h2><span class="tag coral">01</span> Eventi</h2>
      <details class="composer">
        <summary class="btn">+ Proponi un evento</summary>
        <form method="post" action="action.php" class="card form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create_event">
          <label>Titolo <input name="title" required maxlength="140"></label>
          <div class="row">
            <label>Data <input type="date" name="event_date" required min="<?= $today ?>"></label>
            <label>Ora <input type="time" name="event_time"></label>
          </div>
          <label>Luogo <input name="location" maxlength="140"></label>
          <label>Descrizione <textarea name="description" rows="3" maxlength="3000"></textarea></label>
          <button class="btn">Pubblica evento</button>
        </form>
      </details>
    </div>
    <?php if (!$upcoming): ?><p class="empty">Nessun evento in programma. Proponine uno!</p><?php endif; ?>
    <div class="events">
      <?php foreach ($upcoming as $ev) event_card($ev, $uid); ?>
    </div>
    <?php if ($past): ?>
    <details class="past-events">
      <summary>Eventi passati (<?= count($past) ?>)</summary>
      <div class="events"><?php foreach ($past as $ev) event_card($ev, $uid, true); ?></div>
    </details>
    <?php endif; ?>
  </div>
</section>

<!-- DISCUSSIONE -->
<section class="section alt" id="discussione">
  <div class="wrap">
    <div class="section-head">
      <h2><span class="tag teal">02</span> Discussione</h2>
      <details class="composer">
        <summary class="btn">+ Nuovo topic</summary>
        <form method="post" action="action.php" class="card form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create_topic">
          <label>Titolo <input name="title" required maxlength="200"></label>
          <label>Categoria
            <select name="category">
              <?php foreach (TOPIC_CATEGORIES as $c): ?><option><?= e($c) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label>Testo <textarea name="body" rows="5" maxlength="10000" placeholder="Idee, domande, link a bandi o concorsi..."></textarea></label>
          <button class="btn">Pubblica</button>
        </form>
      </details>
    </div>
    <div class="filters">
      <a class="chip<?= $sort === 'new' ? ' on' : '' ?>" href="?sort=new<?= $cat ? '&cat=' . urlencode($cat) : '' ?>#discussione">Recenti</a>
      <a class="chip<?= $sort === 'top' ? ' on' : '' ?>" href="?sort=top<?= $cat ? '&cat=' . urlencode($cat) : '' ?>#discussione">Più votati</a>
      <span class="sep"></span>
      <a class="chip<?= !$cat ? ' on' : '' ?>" href="?sort=<?= $sort ?>#discussione">Tutti</a>
      <?php foreach (TOPIC_CATEGORIES as $c): ?>
        <a class="chip<?= $cat === $c ? ' on' : '' ?>" href="?sort=<?= $sort ?>&cat=<?= urlencode($c) ?>#discussione"><?= e($c) ?></a>
      <?php endforeach; ?>
    </div>
    <?php if (!$topics): ?><p class="empty">Ancora nessun topic<?= $cat ? ' in questa categoria' : '' ?>. Rompi il ghiaccio.</p><?php endif; ?>
    <div class="topics">
      <?php foreach ($topics as $t): ?>
      <article class="card topic">
        <?= vote_box('topic', (int)$t['id'], (int)$t['score'], (int)$t['mine'], 'index.php?sort=' . $sort . ($cat ? '&cat=' . urlencode($cat) : '') . '#discussione') ?>
        <div class="topic-body">
          <p class="label"><?= e($t['category']) ?></p>
          <h3><a href="topic.php?id=<?= (int)$t['id'] ?>"><?= e($t['title']) ?></a></h3>
          <?php if ($t['body']): ?><p class="excerpt"><?= e(mb_strimwidth($t['body'], 0, 220, '…')) ?></p><?php endif; ?>
          <p class="meta"><?= e($t['author']) ?> · <?= ago((int)$t['created_at']) ?> · <a href="topic.php?id=<?= (int)$t['id'] ?>#commenti"><?= (int)$t['n_comments'] ?> comment<?= (int)$t['n_comments'] === 1 ? 'o' : 'i' ?></a></p>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- MATERIALI -->
<section class="section" id="materiali">
  <div class="wrap">
    <div class="section-head">
      <h2><span class="tag sun">03</span> Materiali</h2>
      <details class="composer">
        <summary class="btn">+ Carica un documento</summary>
        <form method="post" action="action.php" enctype="multipart/form-data" class="card form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload">
          <label>File (max <?= MAX_UPLOAD_BYTES / 1048576 ?> MB)
            <input type="file" name="file" required accept="<?= e(implode(',', array_map(fn($x) => '.' . $x, ALLOWED_EXT))) ?>">
          </label>
          <label>Titolo <input name="title" maxlength="200" placeholder="Se vuoto, uso il nome del file"></label>
          <label>Perché è interessante? <textarea name="description" rows="2" maxlength="1000"></textarea></label>
          <button class="btn">Carica</button>
        </form>
      </details>
    </div>
    <?php if (!$docs): ?><p class="empty">Nessun materiale caricato. Slide, paper, script R o Python, bandi: tutto utile.</p><?php endif; ?>
    <div class="docs">
      <?php foreach ($docs as $d): $ext = strtolower(pathinfo($d['original_name'], PATHINFO_EXTENSION)); ?>
      <article class="card doc">
        <span class="ext"><?= e($ext) ?></span>
        <div class="doc-body">
          <h3><a href="download.php?id=<?= (int)$d['id'] ?>"><?= e($d['title']) ?></a></h3>
          <?php if ($d['description']): ?><p><?= rich($d['description']) ?></p><?php endif; ?>
          <div class="meta"><?= e($d['original_name']) ?> · <?= fmt_size((int)$d['size']) ?> · <?= e($d['author']) ?> · <?= ago((int)$d['created_at']) ?>
            <?php if (can_manage($d['user_id'])): ?> · <?= delete_button('delete_doc', (int)$d['id'], 'index.php#materiali') ?><?php endif; ?>
          </div>
        </div>
        <a class="btn sm ghost" href="download.php?id=<?= (int)$d['id'] ?>">Scarica</a>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- MEMBRI -->
<section class="section alt" id="membri">
  <div class="wrap">
    <div class="section-head">
      <h2><span class="tag lilac">04</span> Membri <small><?= count($members) ?></small></h2>
    </div>
    <div class="members">
      <?php foreach ($members as $m): $isMe = (int)$m['id'] === $uid; ?>
      <article class="card member<?= $isMe ? ' me' : '' ?>">
        <span class="avatar" style="background:<?= avatar_color($m['name']) ?>"><?= e(initials($m['name'])) ?></span>
        <h3><?= e($m['name']) ?><?= $isMe ? ' <small>(tu)</small>' : '' ?><?= $m['is_admin'] ? ' <span class="badge">admin</span>' : '' ?></h3>
        <?php if ($m['bio']): ?><p><?= e($m['bio']) ?></p><?php elseif (!$isMe): ?><p class="muted">Nessuna presentazione.</p><?php endif; ?>
        <p class="meta">Dal <?= fmt_day((int)$m['created_at']) ?> · <?= (int)$m['n_topics'] ?> topic · <?= (int)$m['n_comments'] ?> commenti · <?= (int)$m['n_docs'] ?> file</p>
        <?php if ($isMe): ?>
        <details>
          <summary class="linkbtn"><?= $m['bio'] ? 'Modifica presentazione' : 'Aggiungi una presentazione' ?></summary>
          <form method="post" action="action.php" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_bio">
            <textarea name="bio" rows="3" maxlength="400" placeholder="Ufficio, temi di cui ti occupi, interessi..."><?= e($m['bio']) ?></textarea>
            <button class="btn sm">Salva</button>
          </form>
        </details>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php page_end();
