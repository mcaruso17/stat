<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

if (current_user()) redirect('index.php');
if (!empty($_SESSION['quiz_passed'])) redirect('auth.php?mode=register');

$error = null;
$given = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $correct = 0;
    foreach (QUIZ as $i => $q) {
        $a = $_POST['q' . $i] ?? null;
        $given[$i] = $a === null ? null : (int)$a;
        if ($a !== null && (int)$a === $q['answer']) $correct++;
    }
    if ($correct >= QUIZ_MIN_CORRECT) {
        session_regenerate_id(true);
        $_SESSION['quiz_passed'] = true;
        flash("Benvenuto/a, $correct su 3. Ora crea il tuo account.");
        redirect('auth.php?mode=register');
    }
    $error = "Solo $correct risposta" . ($correct === 1 ? '' : 'e') . " giusta su 3. Ne servono almeno " . QUIZ_MIN_CORRECT . ". Riprova.";
}

page_start('Accesso', false);
?>
<?php if (!$error) intro('', '<a class="btn big light" href="#quiz">Entra</a>'); ?>
<section class="section gate" id="quiz">
  <div class="wrap">
    <?php section_head('Area riservata', 'Per assicurarci di avere qui solo codice Stat, rispondi alle seguenti domande.',
        'Ne bastano due giuste su tre. Poi ti registri con nome e password e sei dentro.'); ?>
    <?php if ($error): ?><div class="alert"><?= icon('info') ?><span><?= e($error) ?></span></div><?php endif; ?>
    <form method="post" action="gate.php" class="quiz">
      <?= csrf_field() ?>
      <?php foreach (QUIZ as $i => $q): ?>
      <fieldset class="tile quiz-q">
        <legend><span class="qnum">Domanda <?= $i + 1 ?> di <?= count(QUIZ) ?></span><span class="qtext"><?= e($q['q']) ?></span></legend>
        <div class="options">
          <?php foreach ($q['options'] as $j => $opt): ?>
          <label class="option">
            <input type="radio" name="q<?= $i ?>" value="<?= $j ?>" required <?= ($given[$i] ?? null) === $j ? 'checked' : '' ?>>
            <span><?= e($opt) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <?php endforeach; ?>
      <div class="quiz-submit">
        <button class="btn big">Verifica le risposte</button>
        <p class="muted small" style="margin-top:18px">Hai già un account? <a href="auth.php?mode=login">Accedi</a></p>
      </div>
    </form>
  </div>
</section>
<?php page_end();
