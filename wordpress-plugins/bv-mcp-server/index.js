#!/usr/bin/env node
/*
 * BV Rental Manager ― Claude 連携用 MCP サーバー（読み取り専用）
 *
 * 中央プラグインのデータAPI（/wp-json/bvrm/v1/data/…）を呼び出して、
 * 売上・稼働率・予約の見出しを Claude から参照できるようにする。
 *
 * このサーバーはデータを書き換えない。中央側も読み取り専用のキーで受けている。
 *
 * 使い方（環境変数）
 *   BV_API_URL  例: https://be-village.com/wp-json/bvrm/v1/data/
 *   BV_API_KEY  管理画面「設定 → スタッフポータル・API」に表示される連携キー
 *
 * 依存パッケージなし（Node 18以降の fetch と標準入出力だけで動く）。
 */

const API_URL = (process.env.BV_API_URL || '').replace(/\/?$/, '/');
const API_KEY = process.env.BV_API_KEY || '';
const PROTOCOL_VERSION = '2025-06-18';

if (!API_URL || !API_KEY) {
  process.stderr.write('BV_API_URL と BV_API_KEY を設定してください。\n');
  process.exit(1);
}

/* ---------- 中央サイトの呼び出し ---------- */

async function callApi(name, params) {
  const url = new URL(name, API_URL);
  for (const [k, v] of Object.entries(params || {})) {
    if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v));
  }
  const res = await fetch(url, {
    headers: { 'X-BV-Data-Key': API_KEY, Accept: 'application/json' },
  });
  const text = await res.text();
  if (!res.ok) {
    /* 認証エラーは原因が分かる言葉にして返す */
    if (res.status === 401 || res.status === 403) {
      throw new Error(
        '中央サイトに拒否されました（HTTP ' + res.status + '）。' +
          '連携キーが違うか、管理画面の「データ連携」が無効になっている可能性があります。'
      );
    }
    throw new Error('中央サイトがエラーを返しました（HTTP ' + res.status + '）: ' + text.slice(0, 300));
  }
  try {
    return JSON.parse(text);
  } catch {
    throw new Error('中央サイトの応答を解釈できませんでした: ' + text.slice(0, 300));
  }
}

/* ---------- 公開するツール ---------- */

const PERIOD = {
  from: { type: 'string', description: '開始日 YYYY-MM-DD（省略時は今月1日）' },
  to: { type: 'string', description: '終了日 YYYY-MM-DD（省略時は今月末）' },
  store: {
    type: 'string',
    description:
      '店舗キー。省略すると全店舗。' +
      'hakuba_ekimae=白馬駅前店 / hakuba=コルチナ乗鞍店 / omachi=信濃大町駅前店 / ' +
      'omachi_onsen=大町温泉郷店 / matsumoto=松本島内店 / matsumoto_univ=松本信州大学前店 / ' +
      'hakuba_fromp=長野カーシェアFrom P出張所',
  },
};
const periodSchema = (extra = {}) => ({
  type: 'object',
  properties: { ...PERIOD, ...extra },
  required: [],
});

const TOOLS = [
  {
    name: 'rental_summary',
    description:
      'レンタカー事業の期間まとめを返す。予約件数・売上・返金額・平均単価・貸渡日数・走行距離・' +
      '稼働可能台数・稼働率。「今月の売上は？」「先月の稼働率は？」といった質問に使う。',
    inputSchema: periodSchema(),
    api: 'summary',
  },
  {
    name: 'rental_by_store',
    description: '店舗別の予約件数・売上・平均単価・貸渡日数を返す。店舗間の比較に使う。',
    inputSchema: periodSchema(),
    api: 'by_store',
  },
  {
    name: 'rental_by_class',
    description: '車両クラス別（軽・コンパクト・SUV・ミニバン）の予約件数と売上を返す。',
    inputSchema: periodSchema(),
    api: 'by_class',
  },
  {
    name: 'rental_by_vehicle',
    description:
      '車両1台ごとの売上・稼働率・整備費・リース料・収支を返す（収支の良い順）。' +
      '「稼働率の低い車両は？」「赤字の車両はある？」といった質問に使う。',
    inputSchema: periodSchema(),
    api: 'by_vehicle',
  },
  {
    name: 'rental_monthly',
    description: '月ごとの予約件数・売上・平均単価の推移を返す。期間を長めに指定して使う。',
    inputSchema: periodSchema(),
    api: 'monthly',
  },
  {
    name: 'rental_reservations',
    description:
      '予約の一覧を返す（予約番号・日時・店舗・貸出場所・車両・クラス・姓・金額・入金状況・送迎）。' +
      'お客様の連絡先・住所・生年月日・免許証は含まれない。' +
      '「明日の貸出予定は？」「未入金の予約は？」といった質問に使う。',
    inputSchema: periodSchema({
      status: {
        type: 'string',
        description:
          '絞り込むステータス。pending=仮予約 / confirmed=予約確定 / in_use=貸出中 / ' +
          'returned=返却済 / cancelled=キャンセル。省略するとキャンセル以外すべて。',
      },
      limit: { type: 'number', description: '最大件数（1〜200、既定100）' },
    }),
    api: 'reservations',
  },
  {
    name: 'rental_vehicles',
    description:
      '車両台帳を返す（車両名・ナンバー・クラス・置き場所・走行距離・車検満了日・月額コスト）。' +
      '「車検が近い車両は？」といった質問に使う。',
    inputSchema: {
      type: 'object',
      properties: { store: PERIOD.store },
      required: [],
    },
    api: 'vehicles',
  },
];

/* ---------- MCP（JSON-RPC over stdio） ---------- */

function reply(id, result) {
  process.stdout.write(JSON.stringify({ jsonrpc: '2.0', id, result }) + '\n');
}
function replyError(id, code, message) {
  process.stdout.write(JSON.stringify({ jsonrpc: '2.0', id, error: { code, message } }) + '\n');
}

async function handle(msg) {
  const { id, method, params } = msg;
  /* 通知（idなし）には応答しない */
  const isNotification = id === undefined || id === null;

  if (method === 'initialize') {
    return reply(id, {
      protocolVersion: PROTOCOL_VERSION,
      capabilities: { tools: {} },
      serverInfo: { name: 'bv-rental-manager', version: '1.0.0' },
    });
  }
  if (method === 'tools/list') {
    return reply(id, {
      tools: TOOLS.map(({ name, description, inputSchema }) => ({ name, description, inputSchema })),
    });
  }
  if (method === 'tools/call') {
    const tool = TOOLS.find((t) => t.name === params?.name);
    if (!tool) return replyError(id, -32602, '不明なツールです: ' + params?.name);
    try {
      const data = await callApi(tool.api, params.arguments || {});
      return reply(id, {
        content: [{ type: 'text', text: JSON.stringify(data, null, 2) }],
      });
    } catch (e) {
      /* 失敗はツール結果として返す（会話を止めない） */
      return reply(id, { content: [{ type: 'text', text: 'エラー: ' + e.message }], isError: true });
    }
  }
  if (isNotification) return;
  return replyError(id, -32601, '未対応のメソッドです: ' + method);
}

let buffer = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => {
  buffer += chunk;
  let nl;
  while ((nl = buffer.indexOf('\n')) >= 0) {
    const line = buffer.slice(0, nl).trim();
    buffer = buffer.slice(nl + 1);
    if (!line) continue;
    let msg;
    try {
      msg = JSON.parse(line);
    } catch {
      continue;
    }
    handle(msg).catch((e) => {
      if (msg.id !== undefined && msg.id !== null) replyError(msg.id, -32603, String(e && e.message));
    });
  }
});
