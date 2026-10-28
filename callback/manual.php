<?php
/**
 * 操作マニュアル（ログイン不要）。画面の画像（assets/manual/*.png）に赤い番号を付け、
 * 番号ごとに短い手順を書く。各画面右上の「この画面の使い方」から該当項目（#post など）に飛べる。
 * 印刷して配布してもよい。画像は tools/manual_shots.js で作り直せる。
 */
define('CB_APP', true);
$config = require __DIR__ . '/config.php';
require __DIR__ . '/common.php';

$defaultHours = (int)$config['expire_default_hours'];

/** 画面画像（画像が無いときは何も出さない） */
function cb_manual_img(string $name, string $alt): void
{
    if (!is_file(__DIR__ . '/assets/manual/' . $name . '.png')) { return; }
    echo '<figure class="shot"><img src="assets/manual/' . h($name) . '.png" alt="' . h($alt) . '"></figure>';
}

cb_layout_header($config, '操作マニュアル', 'manual');
?>

<div class="panel manual">
  <p class="print-head"><?= h($config['hospital']) ?>　<?= h($config['title']) ?>　操作マニュアル（<?= h(date('Y年n月j日')) ?> 印刷）</p>
  <h2>操作マニュアル</h2>
  <p class="lead">外線が繋がらなかったら<strong>掲示</strong> → 折り返しを受けた人が<strong>内線へ転送</strong> → 連絡がついたら<strong>終了</strong>。ログイン・パスワードは不要です。</p>
  <div class="actions no-print">
    <button type="button" onclick="window.print()">このマニュアルを印刷</button>
  </div>
  <ol class="toc no-print">
    <li><a href="#post">掲示する</a></li>
    <li><a href="#receive">折り返しを受けたら</a></li>
    <li><a href="#close">連絡がついたら</a></li>
    <li><a href="#edit">内容を直す</a></li>
    <li><a href="#history">過去の掲示を探す</a></li>
    <li><a href="#admin">管理者の方へ</a></li>
  </ol>

  <section id="post">
    <h3>1. 外線が繋がらなかったら（掲示する）</h3>
    <p class="where">画面上部の「掲示する」</p>
    <div class="pair">
    <?php cb_manual_img('post', '掲示する画面'); ?>
    <ul class="marks">
      <li><b class="n">1</b>相手の区分と、<strong>お名前（姓）かID番号</strong>。用件は任意</li>
      <li><b class="n">2</b>あなたの氏名・部署・<strong>内線</strong>。不在時の連絡先もあると安心</li>
      <li><b class="n">3</b>掲示期間（ふつうは <?= $defaultHours ?>時間）</li>
      <li><b class="n">4</b>「掲示する」で全職員の一覧に出ます</li>
    </ul>
    </div>
    <p class="note">お名前は姓のみ。病名・住所は書かないでください。</p>
  </section>

  <section id="receive">
    <h3>2. 折り返しの電話を受けたら（探して転送）</h3>
    <p class="where">画面上部の「折り返し待ち一覧」</p>
    <div class="pair">
    <?php cb_manual_img('list', '折り返し待ち一覧'); ?>
    <ul class="marks">
      <li><b class="n">1</b>相手のお名前・ID番号で探す</li>
      <li><b class="n">2</b>この<strong>内線へ転送</strong>（不在なら「不在のとき」の連絡先へ）</li>
      <li><b class="n">3</b>「詳細」を開いて、受けた記録を残す（下の画面）</li>
    </ul>
    </div>
    <div class="pair">
    <?php cb_manual_img('receive', '折り返しを受けた記録'); ?>
    <ul class="marks">
      <li><b class="n">1</b>受けた方の氏名・部署・メモ</li>
      <li><b class="n">2</b>「折り返しを受けた記録を残す」→ カードに「折り返しあり」と出ます</li>
    </ul>
    </div>
  </section>

  <section id="close">
    <h3>3. 連絡がついたら・取り下げ・延長</h3>
    <p class="where">一覧のカードの「詳細」</p>
    <div class="pair">
    <?php cb_manual_img('close', '連絡がついた・取り下げ・延長'); ?>
    <ul class="marks">
      <li><b class="n">1</b>操作する方の氏名（本人以外でも可）</li>
      <li><b class="n">2</b>「連絡がついた」→ 一覧から消えて履歴へ</li>
      <li><b class="n">3</b>「取り下げ」→ 電話が不要になったとき</li>
      <li><b class="n">4</b>「掲示を延長」→ 期限を延ばす。消えた掲示は「もう一度掲示する」で戻せます</li>
    </ul>
    </div>
    <p class="note">押し忘れても、掲示期間が過ぎると自動で一覧から消えます。</p>
  </section>

  <section id="edit">
    <h3>4. 掲示の内容を直す（編集）</h3>
    <p class="where">一覧のカードの「詳細」</p>
    <div class="pair">
    <?php cb_manual_img('edit', '内容を編集ボタン'); ?>
    <ul class="marks">
      <li><b class="n">1</b>「内容を編集」→ 直して、編集する方の氏名を入れて「変更を保存」</li>
    </ul>
    </div>
    <p class="note">誰が何を変えたかは記録に残ります。</p>
  </section>

  <section id="history">
    <h3>5. 過去の掲示を探す（履歴）</h3>
    <p class="where">画面上部の「履歴」</p>
    <div class="pair">
    <?php cb_manual_img('history', '履歴'); ?>
    <ul class="marks">
      <li><b class="n">1</b>日付・状態・キーワードで探す</li>
      <li><b class="n">2</b>日時を押すと詳細が開きます（直近 <?= (int)$config['retention_days'] ?>日分）</li>
    </ul>
    </div>
  </section>

  <section id="admin">
    <h3>6. 管理者の方へ</h3>
    <ul>
      <li>画面上部の「管理」からログイン。<strong>掲示の削除</strong>・<strong>部署マスタ</strong>（追加・並び替え・使用停止）・<strong>管理者アカウント</strong>の管理ができます。</li>
      <li>部署が選択肢に無いときは「その他」を選んで部署名を記入できます。</li>
    </ul>
  </section>
</div>

<?php cb_layout_footer($config); ?>
