<?php
/**
 * 操作マニュアル（ログイン不要）。
 * 掲示する人・折り返しの電話を受けた人・管理者向けに、画面ごとの操作を説明する。
 * 各画面右上の「この画面の使い方」から該当項目（#post など）に飛べる。印刷して配布してもよい。
 */
define('CB_APP', true);
$config = require __DIR__ . '/config.php';
require __DIR__ . '/common.php';

$hours = array_map('intval', $config['expire_hours']);
$hoursLabel = implode('・', array_map(fn($hr) => $hr % 24 === 0 ? ($hr / 24) . '日' : $hr . '時間', $hours));
$defaultHours = (int)$config['expire_default_hours'];

cb_layout_header($config, '操作マニュアル', 'manual');
?>

<div class="panel manual">
  <p class="print-head"><?= h($config['hospital']) ?>　<?= h($config['title']) ?>　操作マニュアル（<?= h(date('Y年n月j日')) ?> 印刷）</p>
  <h2>操作マニュアル（外線折り返し連絡板）</h2>
  <p class="lead">
    外線をかけて相手に繋がらなかったときに「折り返しがあれば内線○○へ」と院内全体に知らせる掲示板です。
    折り返しの電話を受けた職員は、この掲示を見て担当者の内線へ転送します。
    <strong>ログインもパスワードも不要</strong>で、どの電子カルテ端末からでも使えます。
  </p>
  <div class="actions no-print">
    <a href="index.php" class="btn">折り返し待ち一覧へ</a>
    <a href="new.php" class="btn">＋ 掲示する</a>
    <button type="button" class="secondary" onclick="window.print()">このマニュアルを印刷</button>
  </div>

  <ol class="toc no-print">
    <li><a href="#flow">全体の流れ</a></li>
    <li><a href="#post">外線が繋がらなかったら（掲示する）</a></li>
    <li><a href="#receive">折り返しの電話を受けたら（探して転送）</a></li>
    <li><a href="#close">連絡がついたら・取り下げ・延長</a></li>
    <li><a href="#edit">掲示の内容を直す（編集）</a></li>
    <li><a href="#history">過去の掲示を探す（履歴）</a></li>
    <li><a href="#admin">管理者の方へ</a></li>
    <li><a href="#faq">困ったとき</a></li>
  </ol>

  <h3 id="flow">全体の流れ</h3>
  <ol class="flow">
    <li><strong>掲示</strong>：外線が繋がらなかった職員が「掲示する」から登録する</li>
    <li><strong>転送</strong>：折り返しの電話を受けた職員が一覧で探し、担当者の内線へ転送する。転送したら「折り返しを受けた記録」を残す</li>
    <li><strong>終了</strong>：連絡がついたら「連絡がついた」を押す → 一覧から消え、履歴に残る</li>
    <li>押し忘れても、掲示期間（通常 <?= $defaultHours ?>時間）を過ぎると自動で一覧から消えます</li>
  </ol>

  <h3 id="post">1. 外線が繋がらなかったら（掲示する）</h3>
  <ol>
    <li>画面上部の <strong>「掲示する」</strong> を押します。</li>
    <li><strong>電話した相手</strong>を入力します。
      <ul>
        <li><strong>相手先の区分</strong>（患者本人・患者家族・他の医療機関 など）を選びます。</li>
        <li><strong>お名前（姓のみ）</strong>と<strong>ID番号</strong>を入力します。折り返しを受けた人が照合できるよう、<strong>どちらか一方は必須</strong>です。</li>
        <li><strong>用件の概略</strong>は任意です（例：「外来予約日の変更について」）。</li>
        <li><strong>電話した日・時刻</strong>は、開いた時点の日時が入っています。違う場合は直してください。</li>
      </ul>
    </li>
    <li><strong>あなた（折り返しの転送先）</strong>を入力します。
      <ul>
        <li><strong>氏名</strong>・<strong>所属部署</strong>・<strong>内線・PHS番号</strong>は必須です。部署が一覧に無いときは「その他」を選び、右の欄に部署名を書きます。</li>
        <li>席を外すことが多い場合は、<strong>不在のときの連絡先</strong>（例：詰所 内線3300・リーダー看護師へ）も入れてください。</li>
      </ul>
    </li>
    <li><strong>掲示期間</strong>を選びます（<?= h($hoursLabel) ?>。通常は <?= $defaultHours ?>時間）。</li>
    <li><strong>「掲示する」</strong> を押すと、すぐに全職員の一覧に表示されます。</li>
  </ol>
  <p class="note"><strong>個人情報に注意：</strong>院内の全職員が見る掲示です。お名前は姓のみ、用件は概略にとどめ、病名・住所などは書かないでください。</p>

  <h3 id="receive">2. 折り返しの電話を受けたら（探して転送する）</h3>
  <ol>
    <li>画面上部の <strong>「折り返し待ち一覧」</strong> を開きます（<?= (int)$config['refresh_seconds'] > 0 ? (int)$config['refresh_seconds'] . '秒ごとに自動で最新になります' : '最新にするときは再読み込みしてください' ?>）。</li>
    <li>相手の<strong>お名前</strong>や<strong>ID番号</strong>を、上の検索欄に入れて <strong>「探す」</strong> を押します。担当者名・部署でも探せます。</li>
    <li>カードの <strong>「転送先 → 内線○○」</strong> へ電話を転送します。担当者に繋がらないときは「不在のとき」に書かれた連絡先へ。</li>
    <li>転送・伝言をしたら、カードの <strong>「詳細」</strong> を開き、<strong>「折り返しを受けた記録」</strong>に受けた方の氏名・部署・メモを入れて記録を残します。
      カードに「折り返しあり」と表示され、担当者が折り返しがあったことを確認できます。</li>
  </ol>
  <p class="note">記録を残しても掲示は消えません。掲示を終えるのは、担当者に連絡がついたときです（次の項目）。</p>

  <h3 id="close">3. 連絡がついたら・取り下げ・延長</h3>
  <p>一覧のカードの <strong>「詳細」</strong> を開き、<strong>「連絡がついた・取り下げ・延長」</strong> の欄で操作します。
    <strong>操作する方の氏名</strong>（必須）を入れてからボタンを押してください。掲示した本人のほか、代わりに対応した方も操作できます。</p>
  <ul>
    <li><strong>連絡がついた（掲示を終える）</strong>：一覧から消え、履歴に「連絡がついた」として残ります。メモ欄に「説明済み」などを書けます。</li>
    <li><strong>取り下げ</strong>：電話の必要がなくなったときなど。一覧から消え、履歴に「取り下げ」として残ります。</li>
    <li><strong>掲示を延長</strong>：期限を「今から○時間」に延ばします。終えた掲示・期限切れの掲示では <strong>「もう一度掲示する」</strong> になり、一覧に戻せます。</li>
  </ul>

  <h3 id="edit">4. 掲示の内容を直す（編集）</h3>
  <ol>
    <li>一覧のカードの <strong>「詳細」</strong> を開き、<strong>「内容を編集」</strong> を押します。</li>
    <li>直したいところを書き換え、<strong>編集する方の氏名</strong>（必須）を入れて <strong>「変更を保存」</strong> を押します。</li>
  </ol>
  <p class="note">誰が・何を・どう変えたか（例：「お名前：佐々木様 → 佐藤様」）が詳細画面の記録に残ります。掲示期間は編集では変わりません（「掲示を延長」を使います）。</p>

  <h3 id="history">5. 過去の掲示を探す（履歴）</h3>
  <ul>
    <li>画面上部の <strong>「履歴」</strong> で、連絡がついた・取り下げ・期限切れを含む掲示を、電話した日・状態・キーワードで探せます。</li>
    <li>履歴に残るのは直近 <?= (int)$config['retention_days'] ?>日分です。それより古い掲示と記録は自動で削除されます。</li>
  </ul>

  <h3 id="admin">6. 管理者の方へ</h3>
  <p>画面上部の <strong>「管理」</strong> からログインします（管理者アカウントが必要です）。</p>
  <ul>
    <li><strong>掲示の削除</strong>：誤って個人情報を書いた掲示などは「最近の掲示」の「削除」で消します（記録ごと消去されます）。</li>
    <li><strong>部署マスタ</strong>：掲示や受電記録で選ぶ部署の一覧です。追加・コードや名称の変更・並び替え（↑↓）・使用停止／再開ができます。
      名称を変えても過去の掲示の表記は変わりません。「その他」は停止・改名できません。</li>
    <li><strong>管理者アカウント</strong>：管理者の追加・パスワード変更・無効化ができます。</li>
    <li>ログイン中は、詳細画面の操作や編集が管理者の名前で記録されます。</li>
  </ul>

  <h3 id="faq">7. 困ったとき</h3>
  <dl class="detail faq">
    <dt>一覧に掲示が見つからない</dt>
    <dd>連絡がついた・期限切れになった可能性があります。「履歴」で探してください。探す言葉を短くする（姓だけ・IDの一部だけ）と見つかりやすくなります。</dd>
    <dt>間違えて「連絡がついた」を押した</dt>
    <dd>詳細画面の「もう一度掲示する」で一覧に戻せます。</dd>
    <dt>掲示の内容を間違えた</dt>
    <dd>詳細画面の「内容を編集」で直してください。個人情報を書いてしまった場合は、管理者に削除を依頼してください。</dd>
    <dt>部署が選択肢に無い</dt>
    <dd>「その他」を選んで部署名を記入してください。選択肢に追加したい場合は管理者に依頼してください。</dd>
    <dt>画面が古いままに見える</dt>
    <dd>ブラウザの再読み込み（F5キー）を押してください。</dd>
  </dl>
</div>

<?php cb_layout_footer($config); ?>
