<?php
/**
 * Visualization Lab.
 *
 *   /viz-lab.php            → gallery of curated subjects
 *   /viz-lab.php?s=<slug>   → one subject: original + museum description, with a
 *                             panel of text-to-image models you can run live and
 *                             compare (side-by-side, CLIP score, vision judge).
 */

declare(strict_types=1);

require __DIR__ . '/settings.php';                 // $pdo
require_once __DIR__ . '/lib/viz_lab.php';

$slug = isset($_GET['s']) ? viz_slugify((string)$_GET['s']) : null;

$group   = [];     // language variants of one artwork (sharing group_key), en first
$primary = null;   // representative subject for the header + original image
$gen     = [];     // [subject_id][model] => latest generation (prefers a done render)

if ($slug) {
    $st = $pdo->prepare('SELECT * FROM viz_lab_subjects WHERE slug = ? AND is_published = 1');
    $st->execute([$slug]);
    if ($found = $st->fetch(PDO::FETCH_ASSOC)) {
        $gs = $pdo->prepare("SELECT * FROM viz_lab_subjects
                             WHERE group_key = ? AND is_published = 1
                             ORDER BY (lang = 'en') DESC, lang");
        $gs->execute([$found['group_key']]);
        $group   = $gs->fetchAll(PDO::FETCH_ASSOC);
        $primary = $group[0] ?? $found;

        $ids = array_map(fn($s) => (int)$s['id'], $group);
        if ($ids) {
            $in = implode(',', $ids);
            foreach ($pdo->query("SELECT * FROM viz_lab_generations WHERE subject_id IN ($in) ORDER BY id DESC") as $row) {
                $sid = (int)$row['subject_id']; $m = $row['model'];
                if (!isset($gen[$sid][$m])) $gen[$sid][$m] = $row;
                elseif ($gen[$sid][$m]['status'] !== 'done' && $row['status'] === 'done') $gen[$sid][$m] = $row;
            }
        }
    }
}

// Gallery: one card per artwork (group_key), language variants collapsed.
$groups = [];
if (!$primary) {
    foreach ($pdo->query("SELECT * FROM viz_lab_subjects WHERE is_published = 1
                          ORDER BY (lang='en') DESC, sort_order, title")->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $k = $s['group_key'];
        if (!isset($groups[$k])) { $s['langs'] = [$s['lang']]; $groups[$k] = $s; }
        else { $groups[$k]['langs'][] = $s['lang']; }
    }
}

$configured = (bool)viz_openrouter_key();
$pageTitle  = ($primary ? $primary['title'] . ' — ' : '') . 'Visualization Lab — Artist Networks';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }
function pct(?float $v): string { return $v === null ? '—' : round($v * 100) . '%'; }
function lang_label(string $l): string {
    return ['en' => 'English', 'es' => 'Español', 'fr' => 'Français', 'pt' => 'Português'][$l] ?? strtoupper($l);
}

/** One model×language result card: image left, lang badge + scores + critique right. */
function viz_result_card(array $subj, string $model, ?array $g, bool $configured): string {
    $isDone  = $g && $g['status'] === 'done' && $g['image_path'];
    $isError = $g && $g['status'] === 'error';
    $btn  = $isDone ? 'Regenerate' : ($isError ? 'Retry' : 'Generate');
    $note = $isError ? ('Declined / failed: ' . mb_strimwidth((string)$g['error'], 0, 240, '…')) : ($isDone ? (string)$g['judge_notes'] : '');
    ob_start(); ?>
    <div class="viz-model<?= $isDone ? ' has-image' : ($isError ? ' is-error' : '') ?>" data-subject="<?= h($subj['slug']) ?>" data-model="<?= h($model) ?>">
      <div class="viz-model-media">
        <div class="viz-model-img">
          <?php if ($isDone): ?>
            <img src="<?= h(VIZ_CACHE_URL . '/' . $g['image_path']) ?>" alt="reconstruction (<?= h(lang_label($subj['lang'])) ?>)">
          <?php elseif ($isError): ?>
            <div class="viz-placeholder"><span>no image returned</span></div>
          <?php else: ?>
            <div class="viz-placeholder"><span>not generated yet</span></div>
          <?php endif ?>
        </div>
      </div>
      <div class="viz-model-info">
        <div class="viz-model-head"><span class="viz-lang-badge"><?= h(lang_label($subj['lang'])) ?></span></div>
        <div class="viz-model-scores">
          <span class="viz-score" title="CLIP image similarity to the original">CLIP <strong class="viz-clip"><?= $isDone ? pct($g['clip_similarity'] !== null ? (float)$g['clip_similarity'] : null) : '—' ?></strong></span>
          <span class="viz-score" title="Vision-model resemblance judgement (0–100)">Judge <strong class="viz-judge"><?= $isDone && $g['judge_score'] !== null ? (int)$g['judge_score'] : '—' ?></strong></span>
        </div>
        <p class="viz-model-notes"><?= h($note) ?></p>
        <button class="viz-btn" type="button" <?= $configured ? '' : 'disabled' ?>><?= $btn ?></button>
      </div>
    </div>
    <?php return ob_get_clean();
}
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="x-ua-compatible" content="ie=edge">
  <title><?= h($pageTitle) ?></title>
  <meta name="description" content="Run museum visual descriptions through a sample of AI image models and compare the results to the original artwork.">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="manifest" href="site.webmanifest">
  <link rel="apple-touch-icon" href="https://networks.vaguespac.es/tile.png">
  <link rel="stylesheet" href="css/normalize.css">
  <link rel="stylesheet" href="css/main.css">
  <link rel="stylesheet" href="css/viz-lab.css">
  <script defer src="https://analytics.vaguespac.es/script.js" data-website-id="4261d199-04cb-456f-a3ce-e2d22b4bfd17"></script>
</head>
<body>
  <a class="skip-link" href="#main">Skip to main content</a>
  <div class="site-title-container">
    <nav class="site-title" aria-label="Primary">
      <a href="/"><h1>ARTIST &middot; NETWORKS</h1></a>
      <ul class="sub-menu">
        <li><a href="/about.html">About</a></li>
        <li><a href="/reports.php">Reports</a></li>
        <li><a href="/viz-lab.php" aria-current="page">Lab</a></li>
        <li><a href="/updates.php">Updates</a></li>
        <li><a href="/bacon.html">Bacon</a></li>
      </ul>
    </nav>
  </div>

  <main id="main" class="container">
    <div class="about updates viz-lab">

    <?php if (!$primary): ?>
      <p class="reports-breadcrumb"><a href="/reports.php">← Reports</a></p>
      <h1>Visualization Lab</h1>
      <p class="reports-blurb">
        Museums write <em>visual descriptions</em> — verbal accounts of an artwork for people who
        can't see it. This lab feeds those descriptions, and nothing else, to a sample of
        text-to-image models, then sets each render beside the original so you can see how much of a
        painting survives the trip through words and back into pixels.
      </p>
      <?php if (!$configured): ?>
        <p class="viz-note">⚠️ Generation isn't wired up yet — an OpenRouter API key needs to be added before models can run.</p>
      <?php endif ?>

      <?php if (!$groups): ?>
        <p><em>No subjects curated yet. Import one with <code>php scripts/viz_lab_import_pamm.php &lt;object-number&gt;</code>.</em></p>
      <?php else: ?>
        <div class="viz-gallery">
          <?php foreach ($groups as $s): ?>
            <a class="viz-card" href="/viz-lab.php?s=<?= h($s['slug']) ?>">
              <div class="viz-card-img" style="background-image:url('<?= h($s['original_image_url']) ?>')"
                   role="img" aria-label="<?= h($s['title']) ?>"></div>
              <div class="viz-card-meta">
                <h2><?= h($s['title']) ?></h2>
                <p><?= h(trim(($s['artist'] ? $s['artist'] : '') . ($s['museum'] ? ' · ' . $s['museum'] : ''), ' ·')) ?></p>
                <p class="viz-card-langs"><?php foreach ($s['langs'] as $L): ?><span class="viz-lang-badge"><?= h(lang_label($L)) ?></span><?php endforeach ?></p>
              </div>
            </a>
          <?php endforeach ?>
        </div>
      <?php endif ?>

    <?php else: ?>
      <p class="reports-breadcrumb"><a href="/viz-lab.php">← All subjects</a></p>
      <h1><?= h($primary['title']) ?></h1>
      <p class="reports-blurb">
        <?= h(trim(($primary['artist'] ?? '') . ($primary['date_text'] ? ', ' . $primary['date_text'] : ''), ', ')) ?>
        <?php if ($primary['museum']): ?><br><?= h($primary['museum']) ?><?php endif ?>
        <?php if ($primary['medium']): ?> · <?= h($primary['medium']) ?><?php endif ?>
      </p>

      <figure class="viz-original-fig">
        <img src="<?= h($primary['original_image_url']) ?>" alt="<?= h($primary['title']) ?>">
        <figcaption>Original<?php if ($primary['credit']): ?><span class="viz-credit"><?= h($primary['credit']) ?></span><?php endif ?></figcaption>
      </figure>

      <div class="viz-descs">
        <?php foreach ($group as $subj): ?>
          <div class="viz-desc">
            <h2>Visual description <span class="viz-lang-badge"><?= h(lang_label($subj['lang'])) ?></span></h2>
            <p><?= nl2br(h($subj['visual_description'])) ?></p>
            <?php if ($subj['source_url']): ?>
              <p class="viz-source"><a href="<?= h($subj['source_url']) ?>" rel="noopener" target="_blank">Source ↗</a></p>
            <?php endif ?>
          </div>
        <?php endforeach ?>
      </div>
      <p class="viz-desc-note">The artist's name and the work's title are removed from each description before it is sent to the image models.</p>

      <h2 class="viz-models-head">Model reconstructions<?= count($group) > 1 ? ' — ' . h(implode(' vs ', array_map(fn($s) => lang_label($s['lang']), $group))) : '' ?></h2>
      <p class="viz-models-sub">Each model reads only the description(s) above. Generation is live and cached — the first run may take up to a minute.</p>

      <div class="viz-compare" data-configured="<?= $configured ? '1' : '0' ?>">
        <?php foreach (VIZ_MODELS as $model => $label): ?>
          <section class="viz-modelgroup">
            <h3 class="viz-modelgroup-head"><?= h($label) ?> <code><?= h($model) ?></code></h3>
            <div class="viz-langrow">
              <?php foreach ($group as $subj) echo viz_result_card($subj, $model, $gen[(int)$subj['id']][$model] ?? null, $configured); ?>
            </div>
          </section>
        <?php endforeach ?>
      </div>
    <?php endif ?>

    </div>
  </main>

  <div class="footer">
    <div class="info">
      <ul>
        <li><a href="/">networks.vaguespac.es</a></li>
        <li>Explore the social networks of artists.</li>
        <li class="lede">by <a href="https://www.jaymollica.com">Jay Mollica</a></li>
        <li><a href="/about.html">About</a></li>
        <li>&copy; <span class="current-year">2026</span> Vague Media, LLC</li>
      </ul>
    </div>
  </div>

  <script src="js/vendor/jquery-3.3.1.min.js"></script>
  <script>$(".current-year").text(new Date().getFullYear());</script>
  <script src="js/viz-lab.js"></script>
</body>
</html>
