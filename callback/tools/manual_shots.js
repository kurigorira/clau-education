/**
 * 操作マニュアル用の画面画像（assets/manual/*.png）を作り直すスクリプト。
 * 画面を変えたときだけ、開発用PCで実行する（院内サーバでの実行は不要）。
 *
 *   1. デモデータを入れる:   php tools/demo_data.php --clear
 *   2. アプリを起動しておく: php -S 127.0.0.1:8088 -t .
 *   3. 実行:                 node tools/manual_shots.js [http://127.0.0.1:8088/]
 *
 * Node.js と Playwright（Chromium）が必要。画像には赤い番号（①②③…）を重ねて描き、
 * マニュアル本文の手順番号と対応させる。
 */
const path = require('path');
const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8088/';
const OUT = path.join(__dirname, '..', 'assets', 'manual');

/**
 * 要素の横に番号を描く（[セレクタ, 番号, 向き]）。
 * 向き 'L' は要素の左側（既定）、'R' は右側、'T' は左上の外側。文字に重ならない位置に置く。
 */
async function mark(page, marks) {
  await page.evaluate((marks) => {
    document.querySelectorAll('.shot-mark').forEach(e => e.remove());
    for (const [sel, n, side] of marks) {
      const el = document.querySelector(sel);
      if (!el) { throw new Error('not found: ' + sel); }
      const r = el.getBoundingClientRect();
      const size = 28;
      let x = r.left - size - 6, y = r.top + r.height / 2 - size / 2;
      if (side === 'R') { x = r.right + 6; }
      if (side === 'T') { x = r.left - size / 2; y = r.top - size + 4; }
      const m = document.createElement('div');
      m.className = 'shot-mark';
      m.textContent = n;
      Object.assign(m.style, {
        position: 'absolute', left: (x + window.scrollX) + 'px', top: (y + window.scrollY) + 'px',
        width: size + 'px', height: size + 'px', borderRadius: '50%', background: '#d40000', color: '#fff',
        font: 'bold 17px/28px sans-serif', textAlign: 'center', zIndex: 9999, boxShadow: '0 0 0 3px #fff',
      });
      document.body.appendChild(m);
    }
  }, marks);
}

/** 複数の要素を囲む範囲を余白つきで切り出して保存する */
async function shot(page, name, sels, pad = 16) {
  const boxes = await page.evaluate((sels) => sels.map(s => {
    const r = document.querySelector(s).getBoundingClientRect();
    return [r.left + window.scrollX, r.top + window.scrollY, r.right + window.scrollX, r.bottom + window.scrollY];
  }), sels);
  const x1 = Math.max(0, Math.min(...boxes.map(b => b[0])) - pad), y1 = Math.max(0, Math.min(...boxes.map(b => b[1])) - pad);
  const x2 = Math.max(...boxes.map(b => b[2])) + pad, y2 = Math.max(...boxes.map(b => b[3])) + pad;
  await page.screenshot({ path: path.join(OUT, name + '.png'), clip: { x: x1, y: y1, width: x2 - x1, height: y2 - y1 }, fullPage: true });
  console.log('saved', name + '.png');
}

(async () => {
  const browser = await chromium.launch({ args: ['--lang=ja-JP'] });
  const page = await browser.newPage({ viewport: { width: 1100, height: 900 }, deviceScaleFactor: 1, locale: 'ja-JP' });

  // ---- 1. 掲示する ----
  await page.goto(BASE + 'new.php');
  await page.selectOption('#party_type', '患者家族');
  await page.fill('#party_name', '山田様');
  await page.fill('#patient_id', '0012345');
  await page.fill('#purpose', '退院日程のご相談');
  await page.fill('#caller_name', '佐藤 花子');
  await page.selectOption('#caller_dept', '新3階病棟');
  await page.fill('#caller_ext', '3312');
  await page.fill('#alt_contact', '新3階病棟 詰所 内線3300');
  await page.focus('#party_type');
  await mark(page, [['form fieldset:nth-of-type(1) legend', '①', 'R'], ['form fieldset:nth-of-type(2) legend', '②', 'R'],
                    ['form fieldset:nth-of-type(3) legend', '③', 'R'], ['form button[type=submit]', '④', 'T']]);
  await shot(page, 'post', ['form.panel']);

  // ---- 2. 一覧で探して転送 ----
  await page.goto(BASE + 'index.php');
  await mark(page, [['.filter-form button', '①', 'R'], ['.post .post-to .ext', '②', 'R'], ['.post .post-foot a', '③', 'L']]);
  await shot(page, 'list', ['.filter-form', '.posts .post:nth-child(1)', '.posts .post:nth-child(2)']);

  // ---- 3. 詳細：受電記録 ----
  await page.click('.posts .post:nth-child(1) .post-foot a');
  const viewUrl = page.url();
  await page.fill('#recv_name', '山口 一郎');
  await page.selectOption('#recv_dept', '医事課');
  await page.fill('#recv_memo', '担当者へ転送済み');
  await page.focus('#recv_name');
  await mark(page, [['#recv_name', '①', 'L'], ['form:has(#recv_name) button[type=submit]', '②', 'T']]);
  await shot(page, 'receive', ['.panel:has(#recv_name)']);

  // ---- 4. 詳細：連絡がついた・取り下げ・延長 ----
  await page.goto(viewUrl);
  await page.fill('#actor', '佐藤 花子');
  await page.fill('#note', '説明済み');
  await page.focus('#actor');
  await mark(page, [['#actor', '①', 'L'], ['button[value=done]', '②', 'T'], ['button[value=withdraw]', '③', 'T'], ['button[value=reopen]', '④', 'T']]);
  await shot(page, 'close', ['.panel:has(#actor)']);

  // ---- 5. 編集 ----
  await page.goto(viewUrl);
  await mark(page, [['.post-edit a', '①', 'L']]);
  await shot(page, 'edit', ['.post-detail']);

  // ---- 6. 履歴 ----
  await page.goto(BASE + 'history.php');
  await page.evaluate(() => document.querySelectorAll('table.history tbody tr:nth-child(n+4)').forEach(tr => tr.remove()));
  await mark(page, [['.filter-form button', '①', 'R'], ['table.history tbody tr:first-child td a', '②', 'R']]);
  await shot(page, 'history', ['.filter-form', 'table.history']);

  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
