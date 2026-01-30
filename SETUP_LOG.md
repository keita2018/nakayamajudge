# セットアップログ (2026-01-30)
<!-- このファイルは作業内容の簡易ログです -->

## 何をやったか
<!-- ここは実施事項の要約 -->
- Docker Compose の正しい使い方を確認: `docker compose up --build -d`
- WSL の DNS 解決を修正して `registry-1.docker.io` が引けるようにした
- `docker-compose.yml` が要求するシークレットを作成:
  - `secrets/mysql-user-pw.txt`（値: `domjudge`）
  - `secrets/mysql-root-pw.txt`（値: `root`）
  - `secrets/judgehost-pw.txt`（値: `judgehost`）
- judgehost が `http://domserver/api/v4` に対して 401 を返す認証エラーを確認
- domserver のログから初期 admin パスワードを取得:
  - `Initial admin password is H_CeUTalXiWSfkoG`
- 初期 admin パスワードを `secrets/initial_admin_password.secret` に保存

## 実行したコマンド
<!-- 再現用に実行コマンドを列挙 -->
- `docker compose up --build -d`
- `curl -I https://registry-1.docker.io/v2/`
- `cat /etc/resolv.conf`
- `getent hosts registry-1.docker.io`
- `docker compose up`
- `sudo docker compose logs domserver | grep -o "Initial admin password is .*" | tail -1`

## 補足
<!-- 注意点・補足事項 -->
- DNS 修正は `/etc/wsl.conf` と `/etc/resolv.conf` の手動更新 + WSL 再起動が必要
- `docker info` は docker グループ未加入だと失敗するため、`sudo docker ...` で回避可能
- judgehost が認証で失敗する場合は、domserver ログの "Initial judgehost password" と `secrets/judgehost-pw.txt` を一致させ、`judgehost` を再起動する
<!-- 原因の説明（やさしく） -->
- 原因は WSL の DNS 設定が壊れていて、名前（registry-1.docker.io）を住所に変換できなかったこと
- Windows 本体というより、WSL が Windows の DNS を引き継ぐ部分の設定で詰まっていた
