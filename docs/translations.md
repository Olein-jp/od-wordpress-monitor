# 翻訳ファイルの管理

OD WordPress MonitorとOD Monitor Agentは、プラグインごとにPOT、日本語PO、日本語MOを`languages`ディレクトリへ同梱します。英語をソース言語とし、日本語環境では同梱した翻訳を読み込みます。

## 必要なツール

- リポジトリの依存関係をインストール済みのNode.js/npm環境
- 起動済みのwp-envテスト環境
- GNU gettextの`msgmerge`と`msgfmt`

## 更新手順

ソース内の翻訳対象文字列を変更した場合は、リポジトリルートで次を実行します。

```sh
npm run env:test:start
./scripts/update-plugin-translations.sh
```

一方のプラグインだけを更新する場合は、`monitor`または`agent`を引数に指定できます。

```sh
./scripts/update-plugin-translations.sh monitor
./scripts/update-plugin-translations.sh agent
```

このスクリプトは、wp-envに同梱されたWordPress CLIの`wp i18n make-pot`でPOTを再生成し、既存の日本語POを同期してからMOを再コンパイルします。新しい文字列が追加された場合は、POの空の`msgstr`を日本語へ翻訳してから、もう一度スクリプトを実行してください。

翻訳の検証では、最低限次を確認します。

```sh
msgfmt --check-format --output-file=/dev/null plugins/monitor/languages/od-wordpress-monitor-ja.po
msgfmt --check-format --output-file=/dev/null plugins/agent/languages/od-monitor-agent-ja.po
composer lint
npm run test:php
```

配布ZIPの検証スクリプトは、各プラグインのPOT、ja.po、ja.moが同梱され、空でないことも確認します。
