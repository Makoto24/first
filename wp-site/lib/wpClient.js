// WordPress REST API クライアント（ネイティブ fetch 使用 / ゼロ依存）
import { loadEnv } from './env.js';

export class WpClient {
  constructor() {
    const { baseUrl, username, appPassword } = loadEnv();
    this.baseUrl = baseUrl;
    this.api = `${baseUrl}/wp-json/wp/v2`;
    // アプリケーションパスワードはスペースありのまま Basic 認証に使える
    const token = Buffer.from(`${username}:${appPassword}`).toString('base64');
    this.authHeader = `Basic ${token}`;
  }

  async request(method, path, body) {
    const url = path.startsWith('http') ? path : `${this.api}${path}`;
    const res = await fetch(url, {
      method,
      headers: {
        Authorization: this.authHeader,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: body ? JSON.stringify(body) : undefined,
    });

    const text = await res.text();
    let data;
    try {
      data = text ? JSON.parse(text) : null;
    } catch {
      data = text;
    }

    if (!res.ok) {
      const msg =
        (data && data.message) || `HTTP ${res.status} ${res.statusText}`;
      const err = new Error(`${method} ${url} 失敗: ${msg}`);
      err.status = res.status;
      err.data = data;
      throw err;
    }
    return data;
  }

  // 接続確認（認証込み）
  async verify() {
    return this.request('GET', '/users/me?context=edit');
  }

  // --- 固定ページ ---
  async findPageBySlug(slug) {
    const list = await this.request(
      'GET',
      `/pages?slug=${encodeURIComponent(slug)}&status=publish,draft,pending,private&context=edit`
    );
    return Array.isArray(list) && list.length ? list[0] : null;
  }

  async upsertPage(page) {
    const existing = await this.findPageBySlug(page.slug);
    if (existing) {
      return {
        action: 'updated',
        result: await this.request('POST', `/pages/${existing.id}`, page),
      };
    }
    return {
      action: 'created',
      result: await this.request('POST', '/pages', page),
    };
  }

  // --- 投稿（ブログ記事） ---
  async findPostBySlug(slug) {
    const list = await this.request(
      'GET',
      `/posts?slug=${encodeURIComponent(slug)}&status=publish,draft,pending,private,future&context=edit`
    );
    return Array.isArray(list) && list.length ? list[0] : null;
  }

  async upsertPost(post) {
    const existing = await this.findPostBySlug(post.slug);
    if (existing) {
      return {
        action: 'updated',
        result: await this.request('POST', `/posts/${existing.id}`, post),
      };
    }
    return {
      action: 'created',
      result: await this.request('POST', '/posts', post),
    };
  }

  // --- カテゴリ（なければ作成し ID を返す） ---
  async ensureCategory(name) {
    const list = await this.request(
      'GET',
      `/categories?search=${encodeURIComponent(name)}`
    );
    const hit =
      Array.isArray(list) && list.find((c) => c.name === name);
    if (hit) return hit.id;
    const created = await this.request('POST', '/categories', { name });
    return created.id;
  }
}
