<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$me = require_login();
$uid = (int)$me['id'];
$id = (int)($_GET['id'] ?? 0);
$pdo = db();

$st = $pdo->prepare("SELECT t.*, u.name AS author,
    COALESCE((SELECT SUM(value) FROM votes v WHERE v.target_type = 'topic' AND v.target_id = t.id), 0) AS score,
    COALESCE((SELECT value FROM votes v WHERE v.target_type = 'topic' AND v.target_id = t.id AND v.user_id = :uid), 0) AS mine
    FROM topics t JOIN users u ON u.id = t.user_id WHERE t.id = :id");
$st->execute([':uid' => $uid, ':id' => $id]);
$topic = $st->fetch();
if (!$topic) {
    flash('Topic non trovato.', 'err');
    redirect('index.php#discussione');
}

$st = $pdo->prepare("SELECT c.*, u.name AS author,
    COALESCE((SELECT SUM(value) FROM votes v WHERE v.target_type = 'comment' AND v.target_id = c.id), 0) AS score,
    COALESCE((SELECT value FROM votes v WHERE v.target_type = 'comment' AND v.target_id = c.id AND v.user_id = :uid), 0) AS mine
    FROM comments c JOIN users u ON u.id = c.user_id WHERE c.topic_id = :id
    ORDER BY score DESC, c.created_at ASC");
$st->execute([':uid' => $uid, ':id' => $id]);
$children = [];
$all = $st->fetchAll();
foreach ($all as $c) {
    $children[(int)($c['parent_id'] ?? 0)][] = $c;
}
$back = 'topic.php?id=' . $id;

function comment_form(int $topicId, ?int $parentId, string $placeholder): void
{
    ?>
    <form method="post" action="action.php" class="form comment-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="comment">
      <input type="hidden" name="topic_id" value="<?= $topicId ?>">
      <?php if ($parentId): ?><input type="hidden" name="parent_id" value="<?= $parentId ?>"><?php endif; ?>
      <textarea name="body" rows="3" required maxlength="5000" placeholder="<?= e($placeholder) ?>"></textarea>
      <button class="btn sm">Commenta</button>
    </form>
    <?php
}

function render_comments(array $children, int $parent, int $topicId, int $uid, string $back, int $depth = 0): void
{
    foreach ($children[$parent] ?? [] as $c) {
        $cid = (int)$c['id'];
        ?>
        <div class="comment" id="c<?= $cid ?>">
          <?= vote_box('comment', $cid, (int)$c['score'], (int)$c['mine'], $back . '#c' . $cid) ?>
          <div class="comment-main">
            <div class="comment-head"><span class="avatar xs" style="background:<?= avatar_color($c['author']) ?>"><?= e(initials($c['author'])) ?></span><b><?= e($c['author']) ?></b><span class="meta"><?= ago((int)$c['created_at']) ?></span></div>
            <div class="comment-text"><?= rich($c['body']) ?></div>
            <div class="comment-actions">
              <details>
                <summary class="linkbtn">Rispondi</summary>
                <?php comment_form($topicId, $cid, 'Rispondi a ' . $c['author'] . '...'); ?>
              </details>
              <?php if (can_manage($c['user_id'])): ?><?= delete_button('delete_comment', $cid, $back . '#commenti') ?><?php endif; ?>
            </div>
            <?php if (!empty($children[$cid])): ?>
            <div class="replies<?= $depth >= 5 ? ' flat' : '' ?>">
              <?php render_comments($children, $cid, $topicId, $uid, $back, $depth + 1); ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php
    }
}

page_start($topic['title']);
?>
<section class="section">
  <div class="wrap narrow">
    <a href="index.php#discussione" class="back"><?= icon('left') ?>Discussione</a>
    <article class="tile article">
      <div class="article-head">
        <?= vote_box('topic', $id, (int)$topic['score'], (int)$topic['mine'], $back) ?>
        <div>
          <span class="cat <?= CATEGORY_CLASS[$topic['category']] ?? 'c-gray' ?>"><?= e($topic['category']) ?></span>
          <h1><?= e($topic['title']) ?></h1>
          <div class="article-meta meta">
            <span class="avatar xs" style="background:<?= avatar_color($topic['author']) ?>"><?= e(initials($topic['author'])) ?></span>
            <span><?= e($topic['author']) ?> · <?= ago((int)$topic['created_at']) ?></span>
            <?php if (can_manage($topic['user_id'])): ?><?= delete_button('delete_topic', $id, 'index.php#discussione', 'Elimina topic') ?><?php endif; ?>
          </div>
        </div>
      </div>
      <?php if ($topic['body']): ?><div class="article-text"><?= rich($topic['body']) ?></div><?php endif; ?>
    </article>

    <h2 id="commenti" class="comments-title"><?= count($all) ?> comment<?= count($all) === 1 ? 'o' : 'i' ?></h2>
    <div class="tile composer-tile">
      <?php comment_form($id, null, 'Cosa ne pensi?'); ?>
    </div>
    <?php if ($all): ?>
    <div class="panel comments">
      <?php render_comments($children, 0, $id, $uid, $back); ?>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php page_end();
