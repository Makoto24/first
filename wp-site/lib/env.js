// .env を依存パッケージなしで読み込む簡易ローダー
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const envPath = join(__dirname, '..', '.env');

export function loadEnv() {
  if (existsSync(envPath)) {
    const text = readFileSync(envPath, 'utf8');
    for (const rawLine of text.split('\n')) {
      const line = rawLine.trim();
      if (!line || line.startsWith('#')) continue;
      const eq = line.indexOf('=');
      if (eq === -1) continue;
      const key = line.slice(0, eq).trim();
      let value = line.slice(eq + 1).trim();
      // 前後のクオートを除去
      if (
        (value.startsWith('"') && value.endsWith('"')) ||
        (value.startsWith("'") && value.endsWith("'"))
      ) {
        value = value.slice(1, -1);
      }
      if (!(key in process.env)) {
        process.env[key] = value;
      }
    }
  }

  const required = ['WP_BASE_URL', 'WP_USERNAME', 'WP_APP_PASSWORD'];
  const missing = required.filter((k) => !process.env[k]);
  if (missing.length) {
    console.error(
      `\n[エラー] 環境変数が未設定です: ${missing.join(', ')}\n` +
        `  wp-site/.env.example をコピーして wp-site/.env を作成し、値を設定してください。\n`
    );
    process.exit(1);
  }

  return {
    baseUrl: process.env.WP_BASE_URL.replace(/\/+$/, ''),
    username: process.env.WP_USERNAME,
    appPassword: process.env.WP_APP_PASSWORD,
  };
}
